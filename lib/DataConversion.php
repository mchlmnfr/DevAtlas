<?php
declare(strict_types=1);

/** Session-owned, expiring work files for the offline SQL conversion workflow. */
require_once __DIR__ . '/DataConversion/lib.php';
require_once __DIR__ . '/DataConversion/consol.php';
require_once __DIR__ . '/DataConversion/xlsx.php';

final class DataConversion
{
    public const MAX_UPLOAD = 25 * 1024 * 1024;
    public const MAX_EXPANDED = 80 * 1024 * 1024;
    public const TTL = 3600;
    private const SQL_LIMIT = 128 * 1024 * 1024;
    private const SESSION_KEY = 'devatlas_data_conversion';

    public static function UploadLimit(): int
    {
        $bytes = static function (string $value): int {
            $value = trim($value);
            $number = (float)$value;
            return (int)($number * match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
        };
        $limits = [self::MAX_UPLOAD, $bytes((string)ini_get('upload_max_filesize')), $bytes((string)ini_get('post_max_size'))];
        return min(array_filter($limits, static fn ($limit) => $limit > 0));
    }

    public static function State(): ?array
    {
        $job = self::Job();
        return $job === null ? null : $job['summary'];
    }

    public static function Upload(array $input, array $files): array
    {
        $mode = self::Text($input, 'mode', 'consol');
        if (!in_array($mode, ['consol', 'generic'], true)) throw new DomainException('Choose a conversion type.');
        $delimiter = self::Text($input, 'delimiter', ',');
        if ($delimiter === 'tab') $delimiter = "\t";
        if (!in_array($delimiter, [',', ';', '|', "\t"], true)) throw new DomainException('Choose a valid CSV delimiter.');
        $table = $mode === 'generic'
            ? \DevAtlas\DataConversion\validateTable(self::Text($input, 'table'), 'dbo') : ['dbo', 'consol_data'];
        $source = $files['source'] ?? [];
        $name = is_string($source['name'] ?? null) ? basename(str_replace('\\', '/', $source['name'])) : '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $mode === 'consol' ? ['csv', 'xlsx'] : ['csv'], true))
            throw new DomainException($mode === 'consol' ? 'Select a CSV or XLSX file.' : 'Generic table conversion accepts CSV files.');
        self::Prune();
        $id = bin2hex(random_bytes(16));
        $dir = self::Directory($id);
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create a private conversion folder.');
        try {
            self::SaveUpload($source, $dir . '/source.' . $extension);
            $schema = $files['schema'] ?? [];
            $hasSchema = $mode === 'generic' && ($schema['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasSchema) {
                if (strtolower(pathinfo((string)($schema['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') throw new DomainException('Schema snapshot must be a CSV file.');
                self::SaveUpload($schema, $dir . '/schema.csv');
            }
            $sheets = $extension === 'xlsx' ? \DevAtlas\DataConversion\getXlsxSheetNames($dir . '/source.xlsx', self::MAX_EXPANDED) : [];
            $job = ['id' => $id, 'owner' => (int)($_SESSION['user_id'] ?? 0), 'created' => time(),
                'mode' => $mode, 'extension' => $extension, 'delimiter' => $delimiter, 'table' => $table,
                'filename' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $name), 0, 200),
                'sheets' => $sheets, 'sheet' => '__ALL__', 'schema' => $hasSchema];
            $job = self::Prepare($job);
            $old = self::Job();
            $_SESSION[self::SESSION_KEY] = $job;
            if ($old !== null) self::Remove($old['id']);
            return $job['summary'];
        } catch (Throwable $e) {
            self::Remove($id);
            throw $e;
        }
    }

    public static function Worksheet(array $input): array
    {
        $job = self::RequireJob(self::Text($input, 'job_id'));
        if ($job['extension'] !== 'xlsx') throw new DomainException('This upload has no worksheets.');
        $sheet = self::Text($input, 'sheet', '__ALL__');
        if ($sheet !== '__ALL__' && !in_array($sheet, $job['sheets'], true)) throw new DomainException('Worksheet not found.');
        if ($sheet === 'Summary Count Per Dept') throw new DomainException('The summary worksheet has no report records.');
        $job['sheet'] = $sheet;
        $job = self::Prepare($job);
        $_SESSION[self::SESSION_KEY] = $job;
        return $job['summary'];
    }

    public static function Clear(string $id): void
    {
        $job = self::RequireJob($id);
        self::Remove($job['id']);
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function Download(string $id): array
    {
        $job = self::RequireJob($id);
        $dir = self::Directory($job['id']);
        $output = $dir . '/download.sql';
        $fh = fopen($output, 'wb');
        if (!$fh) throw new RuntimeException('Cannot create SQL download.');
        $failed = false; $bytes = 0; $level = ob_get_level();
        // Stream the original generators into a private file, without buffering the full script in memory.
        ob_start(static function (string $chunk) use ($fh, &$failed, &$bytes): string {
            $bytes += strlen($chunk);
            if ($bytes > self::SQL_LIMIT || (!$failed && fwrite($fh, $chunk) !== strlen($chunk))) $failed = true;
            return '';
        }, 8192);
        try {
            $file = $dir . ($job['extension'] === 'xlsx' ? '/normalized.csv' : '/source.csv');
            $delimiter = $job['extension'] === 'xlsx' ? ',' : $job['delimiter'];
            if ($job['mode'] === 'consol') \DevAtlas\DataConversion\consolSql($file, $delimiter);
            else {
                $cfg = self::Config();
                $spec = \DevAtlas\DataConversion\parseCsv($file, $delimiter, $cfg, $job['schema'] ? \DevAtlas\DataConversion\loadSchema($dir . '/schema.csv') : null, $job['table'], false);
                \DevAtlas\DataConversion\emitSql($file, $delimiter, $cfg, $job['table'], $spec['fields']);
            }
            ob_end_flush();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            fclose($fh);
            @unlink($output);
            throw $e;
        }
        fclose($fh);
        if ($failed) { @unlink($output); throw new DomainException('The SQL script exceeds 128 MB or could not be written. Use a smaller source file.'); }
        return ['path' => $output, 'filename' => ($job['mode'] === 'consol' ? 'consol_data' : implode('_', $job['table'])) . '_' . date('Ymd_His') . '.sql'];
    }

    private static function Prepare(array $job): array
    {
        $dir = self::Directory($job['id']);
        $file = $dir . '/source.csv'; $delimiter = $job['delimiter'];
        if ($job['extension'] === 'xlsx') {
            $file = $dir . '/candidate.csv'; $delimiter = ',';
            \DevAtlas\DataConversion\departmentWorkbookToCsv($dir . '/source.xlsx', $job['sheet'], $file, self::MAX_EXPANDED);
        }
        $spec = $job['mode'] === 'consol'
            ? \DevAtlas\DataConversion\consolRead($file, $delimiter)
            : \DevAtlas\DataConversion\parseCsv($file, $delimiter, self::Config(), $job['schema'] ? \DevAtlas\DataConversion\loadSchema($dir . '/schema.csv') : null, $job['table'], true);
        $columns = $spec['columns'] ?? array_column($spec['fields'], 'name');
        $preview = [];
        foreach ($spec['preview'] as $row) {
            $out = [];
            foreach ($columns as $column) $out[] = mb_strimwidth((string)($row[$column] ?? ''), 0, 500, '…', 'UTF-8');
            $preview[] = $out;
        }
        if ($job['extension'] === 'xlsx' && !rename($file, $dir . '/normalized.csv'))
            throw new RuntimeException('Cannot save the validated worksheet.');
        $job['summary'] = ['job_id' => $job['id'], 'mode' => $job['mode'], 'filename' => $job['filename'],
            'destination' => ($job['mode'] === 'consol' ? 'central_db.' : '') . implode('.', $job['table']),
            'rows' => $spec['rows'], 'columns' => $columns, 'preview' => $preview, 'ignored' => $spec['ignored'],
            'schema_checked' => $job['schema'], 'sheets' => array_values(array_filter($job['sheets'], static fn ($sheet) => $sheet !== 'Summary Count Per Dept')),
            'sheet' => $job['sheet'], 'expires_at' => gmdate('c', $job['created'] + self::TTL)];
        return $job;
    }

    private static function Job(): ?array
    {
        $job = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($job) || !preg_match('/^[a-f0-9]{32}$/D', (string)($job['id'] ?? ''))) { unset($_SESSION[self::SESSION_KEY]); return null; }
        if (($job['owner'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0) || ($job['created'] ?? 0) + self::TTL <= time() || !is_dir(self::Directory($job['id']))) {
            if (($job['created'] ?? 0) + self::TTL <= time()) self::Remove($job['id']);
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return $job;
    }

    private static function RequireJob(string $id): array
    {
        $job = self::Job();
        if ($job === null || !hash_equals($job['id'], $id)) throw new DomainException('This preview has expired or was replaced. Upload and validate your file again.');
        return $job;
    }

    private static function Text(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        if (!is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new DomainException('Invalid conversion option.');
        return $value;
    }

    private static function Config(): array
    {
        return ['system_columns' => ['ReportCode', 'TableName'], 'batch_size' => 500, 'blank_as_null' => true, 'wrap_transaction' => true];
    }

    private static function SaveUpload(array $upload, string $path): void
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) throw new DomainException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The upload exceeds the PHP server limit. Select a smaller file or increase upload_max_filesize and post_max_size.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a source file first.', default => 'The server could not receive the upload.'
        });
        $temp = $upload['tmp_name'] ?? '';
        if (!is_string($temp) || !is_uploaded_file($temp)) throw new DomainException('Invalid file upload.');
        $size = filesize($temp);
        if (!$size || $size > self::MAX_UPLOAD) throw new DomainException('Select a non-empty file no larger than 25 MB.');
        if (!move_uploaded_file($temp, $path)) throw new RuntimeException('Cannot save the private upload.');
        @chmod($path, 0600);
    }

    private static function Directory(string $id): string
    {
        return rtrim(WRITEPATH, '/\\') . '/data-conversion/' . $id;
    }

    private static function Prune(): void
    {
        $root = dirname(self::Directory('unused'));
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Cannot create the private upload directory.');
        $count = 0;
        foreach (new DirectoryIterator($root) as $entry) {
            if (++$count > 1000) break;
            if (!$entry->isLink() && $entry->isDir() && preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename()) && $entry->getMTime() + self::TTL < time())
                self::Remove($entry->getFilename());
        }
    }

    private static function Remove(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return;
        $dir = self::Directory($id);
        foreach (['source.csv', 'source.xlsx', 'schema.csv', 'candidate.csv', 'normalized.csv', 'download.sql'] as $file)
            if (is_file($dir . '/' . $file)) @unlink($dir . '/' . $file);
        if (is_dir($dir)) @rmdir($dir);
    }
}

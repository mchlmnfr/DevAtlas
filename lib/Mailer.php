<?php

/**
 * Filename: Mailer.php
 * Purpose: Provide mailer processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: Mailer.php
 * Thin wrapper around PHPMailer that reads SMTP settings from the
 * system_settings table (managed on the Parameters page) rather than
 * a hardcoded config file, since these are meant to be admin-editable
 * at runtime.
 *
 * Requires `composer install` (see composer.json) to vendor PHPMailer.
 * If it isn't installed, or SMTP isn't configured, sendAccountCreatedEmail()
 * returns a clear error instead of throwing — user creation should never
 * be blocked just because email delivery isn't set up yet.
 */

require_once __DIR__ . '/../config/Database.php';

class Mailer
{
    /** Loads vendor/autoload.php only if present, without exiting the request if it's missing
     *  (unlike lib/vendor_bootstrap.php's require_vendor_autoload(), which is fine for a
     *  dedicated export endpoint but would wrongly abort a user-creation request here). */
    private static function EnsureAutoloaded(): bool
    {
        static $isLoaded = false;
        if ($isLoaded)
        {
            return true;
        }
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload))
        {
            return false;
        }
        require_once $autoload;
        $isLoaded = true;
        return true;
    }
    /** Loads the single-row settings record. */
    private static function Settings(): array
    {
        $db = Database::GetConnection('app');
        $stmt = $db->table('system_settings')->where('id', 1)->get();
        $settings = $stmt->getUnbufferedRow('array');
        return $settings ?: [];
    }

    private static function IsConfigured(array $settings): bool
    {
        return !empty($settings['smtp_host']) && !empty($settings['smtp_from_email']);
    }

    /** Authentication mail has bounded SMTP waits and safe, non-secret delivery notes. */
    public static function SendAuthenticationEmail(array $user, string $purpose, string $secret, ?string $resetUrl): array
    {
        $settings = self::Settings();
        if (!filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL))
        {
            return ['status' => 'unavailable', 'note' => 'There is no valid email address on this account. Update it in User Management.'];
        }
        if (!self::IsConfigured($settings))
        {
            return ['status' => 'unavailable', 'note' => 'Outgoing email is not configured. Check SMTP settings in Parameters.'];
        }
        if (!self::EnsureAutoloaded() || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
        {
            return ['status' => 'unavailable', 'note' => 'The mail library is unavailable. Install the project Composer dependencies.'];
        }
        try
        {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 8;
            $mail->Timelimit = 10;
            $mail->SMTPDebug = 0;
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            $mail->SMTPAuth = !empty($settings['smtp_username']);
            $mail->Username = $settings['smtp_username'] ?? '';
            $mail->Password = $settings['smtp_password'] ?? '';
            if (($settings['smtp_encryption'] ?? '') === 'ssl')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            elseif (($settings['smtp_encryption'] ?? '') === 'tls')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            else
            {
                $mail->SMTPAutoTLS = false;
            }
            $mail->setFrom($settings['smtp_from_email'], $settings['smtp_from_name'] ?: APP_NAME);
            $mail->addAddress($user['email']);
            $isCode = $purpose === 'signin';
            $title = $isCode ? 'Your sign-in code' : 'Reset your password';
            $minutes = $isCode ? 10 : 30;
            $instruction = $isCode
                ? 'Enter this one-time code in the browser where you requested it.'
                : 'Open the link below to choose a new password. Your password stays the same until you complete the reset.';
            $credential = $isCode ? $secret : $resetUrl;
            $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $mail->Subject = APP_NAME . ' - ' . $title;
            $mail->isHTML(true);
            $action = $isCode
                ? '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#5c56b9">' . $escape($secret) . '</p>'
                : '<p><a href="' . $escape($resetUrl ?? '') . '">Choose a new password</a></p>';
            $mail->Body = '<!doctype html><html><body style="font-family:Poppins,Arial,sans-serif;line-height:1.7;color:#0F172A">'
                . '<h2>' . $escape(APP_NAME) . '</h2><h1>' . $title . '</h1><p>' . $instruction . '</p>' . $action
                . '<p>Valid for ' . $minutes . ' minutes and one use only. Requesting a new email replaces the previous one.</p>'
                . '<p>If you did not request this, ignore this email. Do not share the code or reset link.</p></body></html>';
            $mail->AltBody = APP_NAME . "\n" . $title . "\n\n" . $instruction . "\n\n" . $credential
                . "\n\nValid for $minutes minutes and one use only. Requesting a new email replaces the previous one."
                . "\nIf you did not request this, ignore this email. Do not share the code or reset link.";
            $mail->send();
            return ['status' => 'sent', 'note' => 'The SMTP server accepted this message. Inbox delivery is not guaranteed; check spam or your mail gateway.'];
        }
        catch (Throwable $e)
        {
            // SMTP exceptions can contain addresses, hosts and credentials; retain only safe guidance.
            return ['status' => 'failed', 'note' => 'The mail server could not accept this email. Check connectivity and SMTP settings in Parameters, then send a test email.'];
        }
    }

    /**
     * Sends a new-account welcome email containing the Employee ID and
     * temporary password. Returns ['ok' => bool, 'error' => ?string].
     */
    public static function SendAccountCreatedEmail(array $user, string $plainPassword): array
    {
        $settings = self::Settings();
        if (!self::IsConfigured($settings))
        {
            return ['ok' => false, 'error' => 'SMTP is not configured yet — set it up on the Parameters page to enable account-creation emails.'];
        }
        if (empty($user['email']))
        {
            return ['ok' => false, 'error' => 'This user has no email address on file.'];
        }

        if (!self::EnsureAutoloaded() || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
        {
            return ['ok' => false, 'error' => "PHPMailer isn't installed. Run 'composer install' in the application folder."];
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try
        {
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            if (!empty($settings['smtp_username']))
            {
                $mail->SMTPAuth = true;
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'] ?? '';
            }
            else
            {
                $mail->SMTPAuth = false;
            }
            if ($settings['smtp_encryption'] === 'ssl')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            elseif ($settings['smtp_encryption'] === 'tls')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            else
            {
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($settings['smtp_from_email'], $settings['smtp_from_name'] ?? APP_NAME);
            $mail->addAddress($user['email'], trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));

            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            $loginUrl = self::LoginUrl();

            $mail->isHTML(true);
            $mail->Subject = 'Your ' . APP_NAME . ' account has been created';
            $mail->Body = self::RenderHtmlBody($fullName, $user['employee_id'], $plainPassword, $loginUrl);
            $mail->AltBody = self::RenderPlainBody($fullName, $user['employee_id'], $plainPassword, $loginUrl);

            $mail->send();
            return ['ok' => true];
        }
        catch (\PHPMailer\PHPMailer\Exception $e)
        {
            return ['ok' => false, 'error' => 'Email failed to send: ' . $mail->ErrorInfo];
        }
        catch (Throwable $e)
        {
            return ['ok' => false, 'error' => 'Email failed to send: ' . $e->getMessage()];
        }
    }

    /** Sends a short test message to confirm SMTP settings work, from the Parameters page. */
    /** Plain-text reminder delivery; never include source, credentials or raw exceptions. */
    public static function SendNotification(array $notification): array
    {
        $settings = self::Settings();
        if (!self::IsConfigured($settings) || !self::EnsureAutoloaded() || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
        {
            return ['ok' => false];
        }
        try
        {
            require_once __DIR__.'/EmailAuth.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 10;
            $mail->SMTPDebug = 0;
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            $mail->SMTPAuth = !empty($settings['smtp_username']);
            $mail->Username = $settings['smtp_username'] ?? '';
            $mail->Password = $settings['smtp_password'] ?? '';
            if (($settings['smtp_encryption'] ?? '') === 'ssl')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            elseif (($settings['smtp_encryption'] ?? '') === 'tls')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            else
            {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->setFrom($settings['smtp_from_email'], $settings['smtp_from_name'] ?: APP_NAME);
            $mail->addAddress($notification['email']);
            $mail->Subject = APP_NAME.' · '.$notification['title'];
            $mail->Body = $notification['message']."\n\n".EmailAuth::BaseUrl().substr($notification['target_path'], strlen(APP_BASE_URL))."\n\nSign in to view details. Manage reminder emails from Notifications.";
            $mail->send();
            return ['ok' => true];
        }
        catch (Throwable $e)
        {
            return ['ok' => false];
        }
    }
    public static function SendTestEmail(string $toEmail): array
    {
        $settings = self::Settings();
        if (!self::IsConfigured($settings))
        {
            return ['ok' => false, 'error' => 'Fill in Host and From Email first.'];
        }
        if (!self::EnsureAutoloaded() || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class))
        {
            return ['ok' => false, 'error' => "PHPMailer isn't installed. Run 'composer install' in the application folder."];
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try
        {
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            if (!empty($settings['smtp_username']))
            {
                $mail->SMTPAuth = true;
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'] ?? '';
            }
            else
            {
                $mail->SMTPAuth = false;
            }
            if ($settings['smtp_encryption'] === 'ssl')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            elseif ($settings['smtp_encryption'] === 'tls')
            {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            else
            {
                $mail->SMTPAutoTLS = false;
            }
            $mail->setFrom($settings['smtp_from_email'], $settings['smtp_from_name'] ?? APP_NAME);
            $mail->addAddress($toEmail);
            $mail->Subject = APP_NAME . ' — SMTP test';
            $mail->Body = 'This is a test email from ' . APP_NAME . '. If you received this, your SMTP settings are working.';
            $mail->send();
            return ['ok' => true];
        }
        catch (\PHPMailer\PHPMailer\Exception $e)
        {
            return ['ok' => false, 'error' => 'Test email failed: ' . $mail->ErrorInfo];
        }
        catch (Throwable $e)
        {
            return ['ok' => false, 'error' => 'Test email failed: ' . $e->getMessage()];
        }
    }

    private static function LoginUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . APP_BASE_URL . '/login.php';
    }

    private static function RenderHtmlBody(string $fullName, string $employeeId, string $password, string $loginUrl): string
    {
        $appName = htmlspecialchars(APP_NAME, ENT_QUOTES);
        $fullName = htmlspecialchars($fullName, ENT_QUOTES);
        $employeeId = htmlspecialchars($employeeId, ENT_QUOTES);
        $password = htmlspecialchars($password, ENT_QUOTES);
        $loginUrl = htmlspecialchars($loginUrl, ENT_QUOTES);
        $year = Date('Y');

        // Table-based layout with inline styles throughout — the layout most
        // email clients (including older Outlook) render consistently.
        return <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:32px 16px;font-family:Poppins,Arial,Helvetica,sans-serif;">
          <tr>
            <td align="center">
              <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;">

                <!-- Header -->
                <tr>
                  <td style="background:#2563EB;padding:28px 32px;">
                    <div style="color:#FFFFFF;font-size:20px;font-weight:bold;letter-spacing:.2px;">{$appName}</div>
                    <div style="color:#DBEAFE;font-size:12.5px;margin-top:2px;">Enterprise IT Management</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td style="padding:32px;">
                    <p style="margin:0 0 8px;font-size:15px;color:#0F172A;">Hi {$fullName},</p>
                    <p style="margin:0 0 20px;font-size:14px;color:#4b5468;line-height:1.6;">
                      An account has been created for you on {$appName}. Use the credentials below to sign in for the first time.
                    </p>

                    <!-- Credentials box -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:20px;">
                      <tr>
                        <td style="padding:18px 20px;">
                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="padding:6px 0;font-size:12px;color:#6b7488;text-transform:uppercase;letter-spacing:.4px;width:140px;">Employee ID</td>
                              <td style="padding:6px 0;font-size:15px;color:#0F172A;font-weight:bold;font-family:'Courier New',monospace;">{$employeeId}</td>
                            </tr>
                            <tr>
                              <td style="padding:6px 0;font-size:12px;color:#6b7488;text-transform:uppercase;letter-spacing:.4px;">Temporary Password</td>
                              <td style="padding:6px 0;font-size:15px;color:#0F172A;font-weight:bold;font-family:'Courier New',monospace;">{$password}</td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- Security notice -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FDF6E8;border:1px solid #F0E0B8;border-radius:10px;margin-bottom:24px;">
                      <tr>
                        <td style="padding:14px 18px;font-size:13px;color:#8a6d1f;line-height:1.6;">
                          🔒 You will be required to <strong>change this password</strong> the first time you sign in. Please keep these credentials confidential and don't forward this email.
                        </td>
                      </tr>
                    </table>

                    <!-- CTA -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                      <tr>
                        <td style="border-radius:8px;background:#2563EB;">
                          <a href="{$loginUrl}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">
                            Sign In to {$appName}
                          </a>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Footer -->
                <tr>
                  <td style="padding:20px 32px;border-top:1px solid #E2E8F0;">
                    <p style="margin:0;font-size:11.5px;color:#9aa1b0;line-height:1.6;">
                      If you weren't expecting this email, please contact your administrator.<br>
                      © {$year} {$appName}. This is an automated message — please don't reply.
                    </p>
                  </td>
                </tr>

              </table>
            </td>
          </tr>
        </table>
        HTML;
    }

    private static function RenderPlainBody(string $fullName, string $employeeId, string $password, string $loginUrl): string
    {
        return "Welcome to " . APP_NAME . "\n\n"
            . "Hi {$fullName},\n\n"
            . "An account has been created for you:\n"
            . "Employee ID: {$employeeId}\n"
            . "Temporary Password: {$password}\n\n"
            . "You will be required to change this password the first time you log in.\n"
            . "Please keep these credentials confidential.\n\n"
            . "Sign in: {$loginUrl}\n\n"
            . "If you weren't expecting this email, please contact your administrator.\n";
    }
}

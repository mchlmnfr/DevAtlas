# Render staging container

The Docker image serves DevAtlas at `/AnalystToolkit/` on port 10000 using PHP 8.2 and Apache. Use the repository Dockerfile as the Render web service build configuration and `/AnalystToolkit/login` as the health-check path.

The image copies `.env.example` as a placeholder configuration. Existing process environment variables override the template. Real `.env` files, encrypted installation secrets, sessions, logs, uploads and downloadable ZIP archives are excluded from the build context.

Configure the existing DevAtlas variable names in Render:

- `CI_ENVIRONMENT=production`
- `APP_ORIGIN=https://<your-render-service-host>` (origin only, without the application path)
- `APP_BASE_URL=/AnalystToolkit`
- `APP_NAME=DevAtlas` and the desired `APP_TIMEZONE`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SCHEMA`
- `APP_ENCRYPTION_KEY`: preserve the exact existing key when connecting an existing installation. For an isolated new staging database, create a key once and store it in Render's environment settings.

The `.env.example` defaults cover optional legacy `TARGET_DB_*` values; override them if those defaults are used. Native `app.baseURL` and `app.appTimezone` settings are derived from the template using `APP_ORIGIN`, `APP_BASE_URL` and `APP_TIMEZONE`. Legacy `DEVATLAS_DB_*` and `DEVATLAS_PUBLIC_URL` aliases from the original draft are superseded by this shared configuration contract.

Initialize a new staging PostgreSQL database using `database/schema.sql` with your database administration client. Apply applicable module installers, including `php bin/install-data-conversion.php`, against that staging database. Container startup does not run migrations or change an existing database.

Keep a separate staging database. Render's ephemeral filesystem does not preserve uploaded avatars or other user files across deployments; configure persistent storage if those features are used. Test login and the required module workflows before directing users to a hosted instance.

Local build:

```sh
docker build -t devatlas-staging .
```

For a build network that requires a custom CA, supply its certificate using `--secret id=proxy_ca,src=/path/to/ca-bundle.pem`. The certificate is mounted only for Composer's build step and is not copied into the image. Provide runtime environment settings separately when running the image.

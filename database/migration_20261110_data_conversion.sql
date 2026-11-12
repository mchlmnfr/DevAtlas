-- Register the offline converter without changing any destination database or existing grants.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'dataconversion',p.permission,r.role_key='admin'
FROM app_roles r CROSS JOIN (VALUES ('view'),('create'),('export')) p(permission)
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;

<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/TenantContext.php';

final class SvAmazonTenantRegistry
{
    public static function resolveCurrent(PDO $db, SvAmazonReturnsConfig $config): SvAmazonTenantContext
    {
        $slug = self::identity($config->get('AMAZON_RETURNS_TENANT_SLUG'), 'tenant slug');
        $connectionKey = self::identity($config->get('AMAZON_RETURNS_CONNECTION_KEY'), 'connection key');
        $statement = $db->prepare(
            'SELECT t.id AS tenant_id,t.status AS tenant_status,'
            . 'c.id AS amazon_connection_id,c.status AS connection_status '
            . 'FROM amazon_return_tenants t '
            . 'JOIN amazon_return_connections c ON c.tenant_id=t.id '
            . 'WHERE t.slug=:tenant_slug AND c.connection_key=:connection_key LIMIT 1'
        );
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant context lookup.');
        }
        $statement->execute([':tenant_slug'=>$slug, ':connection_key'=>$connectionKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Configured tenant connection was not found.');
        }
        if (strtoupper(trim((string)($row['tenant_status'] ?? ''))) !== 'ACTIVE') {
            throw new RuntimeException('Configured tenant is not active.');
        }
        if (strtoupper(trim((string)($row['connection_status'] ?? ''))) !== 'ACTIVE') {
            throw new RuntimeException('Configured Amazon connection is not active.');
        }
        return new SvAmazonTenantContext(
            (int)($row['tenant_id'] ?? 0),
            (int)($row['amazon_connection_id'] ?? 0)
        );
    }

    private static function identity(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 96 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1) {
            throw new RuntimeException('Invalid configured ' . $label . '.');
        }
        return $value;
    }
}

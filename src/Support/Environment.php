<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support;

/**
 * @internal
 */
final class Environment
{
    /**
     * A short identifier of the user running PHP, used to keep default
     * directories in the shared temp dir separate per user.
     */
    public static function userTag(): string
    {
        if (function_exists('posix_geteuid')) {
            return (string) posix_geteuid();
        }

        return substr(hash('xxh128', (string) get_current_user()), 0, 12);
    }

    /**
     * Default per-user directory inside the system temp dir.
     */
    public static function tempDirectory(string $name): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/' . $name . '-' . self::userTag();
    }

    /**
     * Whether a directory is safe to keep private files in: a real directory
     * (not a symlink), owned by the current user and not writable by others.
     */
    public static function isPrivateDirectory(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }

        if (function_exists('posix_geteuid')) {
            $owner = @fileowner($dir);
            if ($owner === false || $owner !== posix_geteuid()) {
                return false;
            }
        }

        $perms = @fileperms($dir);
        if ($perms !== false && DIRECTORY_SEPARATOR === '/' && ($perms & 0022) !== 0) {
            return false;
        }

        return is_writable($dir);
    }

    /**
     * Create a directory (recursively) with exact permissions, ignoring umask.
     */
    public static function ensureDirectory(string $dir, int $mode): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            return false;
        }

        @chmod($dir, $mode);

        return true;
    }
}

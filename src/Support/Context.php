<?php
namespace Kitgenix\MultiStore\Support;

final class Context {
    private static int $remote_depth = 0;

    public static function enter_remote(): void {
        ++self::$remote_depth;
    }

    public static function leave_remote(): void {
        self::$remote_depth = max( 0, self::$remote_depth - 1 );
    }

    public static function is_remote(): bool {
        return self::$remote_depth > 0;
    }

    public static function remote( callable $callback ): mixed {
        self::enter_remote();
        try {
            return $callback();
        } finally {
            self::leave_remote();
        }
    }
}

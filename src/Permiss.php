<?php
namespace GlpiPlugin\Reservafrota;

use GlpiPlugin\Reservafrota\Booking;
use Session;

class Permiss
{
    public static function canRead(): bool
    {
        return Session::haveRight(Booking::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(Booking::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Booking::$rightname, UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(Booking::$rightname, DELETE);
    }

    public static function canApprove(): bool
    {
        return Booking::canApprove();
    }

    public static function canWrite(): bool
    {
        return Session::haveRightsOr(Booking::$rightname, [CREATE, UPDATE]);
    }

    public static function toArray(): array
    {
        return [
            'read'    => self::canRead(),
            'create'  => self::canCreate(),
            'update'  => self::canUpdate(),
            'delete'  => self::canDelete(),
            'approve' => self::canApprove(),
        ];
    }

    public static function toArry(): array
    {
        return self::toArray();
    }
}

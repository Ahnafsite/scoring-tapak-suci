<?php

namespace App\Enums;

enum WinnerStatus: string
{
    case MENANG_ANGKA = 'menang_angka';
    case MENANG_TEKNIK = 'menang_teknik';
    case MENANG_MUTLAK = 'menang_mutlak';
    case MENANG_WMP = 'menang_wmp';
    case MENANG_UNDUR_DIRI = 'menang_undur_diri';
    case MENANG_DISKUALIFIKASI = 'menang_diskualifikasi';

    public function label(): string
    {
        return match($this) {
            self::MENANG_ANGKA => 'Menang Angka',
            self::MENANG_TEKNIK => 'Menang Teknik',
            self::MENANG_MUTLAK => 'Menang Mutlak',
            self::MENANG_WMP => 'Menang WMP',
            self::MENANG_UNDUR_DIRI => 'Menang Undur Diri',
            self::MENANG_DISKUALIFIKASI => 'Menang Diskualifikasi',
        };
    }
}

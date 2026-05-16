<?php

namespace App\Support;

class BankShortcut
{
    /** اختصارات معروفة — للمطابقة عند كتابة حروف في حقل البنك فقط، وليس حقلاً في الواجهة. */
    private const KNOWN = [
        'بنك فلسطين' => 'ف',
        'البنك العقاري المصري' => 'عق',
        'بنك القاهرة عمان' => 'ق ع',
        'بنك الاردن' => 'ا',
        'البنك العربي' => 'ع',
        'بنك الاستثمار' => 'الاس',
        'البنك الاهلي الاردني' => 'الاه',
        'بنك الإسكان' => 'الإ',
        'البنك الإسلامي الفلسطيني' => 'الإس',
        'البنك الإسلامي العربي' => 'الإسل',
        'بنك القدس' => 'ق',
        'بنك الوطني' => 'و',
        'بنك الصفا' => 'ص',
        'كمبيالة' => 'ك',
    ];

    public static function infer(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        foreach (self::KNOWN as $bankName => $shortcut) {
            if (mb_strtolower($bankName) === mb_strtolower($name)) {
                return $shortcut;
            }
        }

        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return null;
        }

        $first = mb_strtolower($words[0]);
        if (count($words) >= 2 && (str_starts_with($first, 'بنك') || str_starts_with($first, 'البنك'))) {
            return mb_substr($words[1], 0, 1);
        }

        return mb_substr($words[0], 0, 1);
    }
}

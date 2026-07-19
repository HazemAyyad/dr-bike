<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ProductSearchFilter
{
    public static function apply(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        $tokens = self::tokens($search);

        return $query->where(function (Builder $outer) use ($search, $tokens) {
            $outer->where(fn (Builder $exact) => self::whereAnyProductFieldLike($exact, $search));

            if (! empty($tokens)) {
                $outer->orWhere(function (Builder $allTokens) use ($tokens) {
                    foreach ($tokens as $token) {
                        $allTokens->where(function (Builder $oneToken) use ($token) {
                            foreach (self::tokenAliases($token) as $alias) {
                                $oneToken->orWhere(
                                    fn (Builder $aliasQuery) => self::whereAnyProductFieldLike($aliasQuery, $alias)
                                );
                            }
                        });
                    }
                });
            }
        });
    }

    private static function whereAnyProductFieldLike(Builder $query, string $value): Builder
    {
        $term = '%' . self::escapeLike($value) . '%';

        return $query
            ->where('nameAr', 'like', $term)
            ->orWhere('product_code', 'like', $term)
            ->orWhereHas('storeSection', fn (Builder $section) => $section->where('name', 'like', $term))
            ->orWhereHas('sizes', function (Builder $size) use ($term) {
                $size->where('size', 'like', $term)
                    ->orWhereHas('colorSizes', function (Builder $color) use ($term) {
                        $color->where('colorAr', 'like', $term)
                            ->orWhere('colorEn', 'like', $term)
                            ->orWhere('colorAbbr', 'like', $term);
                    });
            });
    }

    private static function tokens(string $text): array
    {
        $normalized = self::normalize($text);
        $parts = preg_split('/[\s,،\/\\\\\-]+/u', $normalized) ?: [];
        $softWords = [
            'عدد', 'كمية', 'حبة', 'حبه', 'قطع', 'قطعة', 'قطعه',
            'عادي', 'كبير', 'كبيره', 'صغير', 'صغيره',
            'جديد', 'جديده', 'قديم', 'قديمه',
        ];

        return collect($parts)
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '' && ! in_array($part, $softWords, true))
            ->filter(fn ($part) => mb_strlen($part) > 1 || preg_match('/\d/u', $part))
            ->unique()
            ->values()
            ->all();
    }

    private static function tokenAliases(string $token): array
    {
        return match (self::normalize($token)) {
            'لوحه' => ['لوحة', 'لوحه', 'كومبيوتر', 'كمبيوتر', 'كنترولر', 'controller'],
            'w', 'وات', 'واط' => ['w', 'وات', 'واط'],
            'فحمات', 'فحمه' => ['فحمات', 'فحمة', 'فحمه', 'فرامل', 'بريك', 'brake'],
            'مربع', 'مربعه' => ['مربع', 'مربعة', 'square'],
            'ضوء', 'ضو' => ['ضوء', 'ضو', 'ليت', 'لمبة', 'لمبه', 'كشاف', 'light'],
            'مدور' => ['مدور', 'دائري', 'دائرة', 'round'],
            'مجوز' => ['مجوز', 'زوج', 'جوز', 'مزدوج', 'double'],
            'اكس' => ['اكس', 'أكس', 'محور', 'اكسل', 'axle', 'motor'],
            'قاعده' => ['قاعدة', 'قاعده', 'حامل', 'بيت', 'base', 'holder'],
            'بطاريه' => ['بطارية', 'بطاريه', 'battery'],
            'بلحه' => ['بلحة', 'بلحه', 'فيشة', 'فيشه', 'مدخل', 'سوكت', 'socket'],
            'شاحن' => ['شاحن', 'شحن', 'charger'],
            'انثي', 'انتى' => ['انثى', 'انثي', 'انتى', 'female', 'f'],
            'حساسات', 'حساس' => ['حساسات', 'حساس', 'sensor'],
            'ماطور', 'ماتور', 'موتور' => ['ماطور', 'ماتور', 'موتور', 'motor'],
            'ضروس', 'ضرس' => ['ضروس', 'ضرس', 'ترس', 'تروس', 'مسنن', 'gear'],
            'مستقيم' => ['مستقيم', 'سنتر', 'straight'],
            'دعسات', 'دعسه' => ['دعسات', 'دعسة', 'دعسه', 'دواسات', 'دواسة', 'pedal'],
            default => self::arabicTokenForms($token),
        };
    }

    private static function arabicTokenForms(string $token): array
    {
        $forms = [$token];
        if (str_ends_with($token, 'ه')) {
            $forms[] = mb_substr($token, 0, -1) . 'ة';
        }
        if (str_ends_with($token, 'ة')) {
            $forms[] = mb_substr($token, 0, -1) . 'ه';
        }

        return array_values(array_unique($forms));
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(self::normalizeDigits($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = preg_replace('/[^\p{Arabic}a-z0-9]+/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}

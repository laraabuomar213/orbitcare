<?php
// Finds symptoms the astronaut actually wrote, in English or Arabic, using the phrase lists in
// config/ai-specialists.php ('phrases' and 'phrases_ar'). Phrases preceded by a negation
// ("no chest pain", "ما عندي دوخة") are ignored. Used only to route — never to diagnose.
//
// Arabic text is normalised before matching (diacritics and tatweel removed; أ/إ/آ → ا, ة → ه,
// ى → ي), so "ألم في الصدر", "الم في الصدر" and "ألمٌ في الصدر" all match the same phrase.
// The quote shown back to the astronaut is taken from their original text.
declare(strict_types=1);

final class SymptomDetector
{
    private const NEGATION_EN = '/\b(no|not|without|never|denies|deny|none|don\'t|do not|didn\'t|did not|haven\'t|have not|isn\'t|is not|wasn\'t)\b[^.!?,;]{0,20}$/i';

    // لا / ما / مش / مو / ليس / بدون / لم … within a few words before the phrase (same sentence).
    private const NEGATION_AR = '/(?:^|[^\p{L}])(لا|ولا|ما|وما|مش|ومش|مو|ليس|لست|ليست|بدون|دون|لم|لن|مافي|ماعندي|ماني)(?:[^\p{L}]|$)[^.!?,;،؛؟]{0,15}$/u';

    public function __construct(private array $symptoms = [])
    {
        $this->symptoms = $symptoms ?: ai_specialists_config()['symptoms'];
    }

    /**
     * @param string[] $texts user messages, oldest first
     * @return array<string, array{key: string, label: string, specialists: string[], red_flag: bool, quote: string}>
     */
    public function detect(array $texts): array
    {
        $found = [];
        foreach ($texts as $text) {
            [$normalized, $map] = self::normalize($text);
            foreach ($this->symptoms as $key => $symptom) {
                $match = $this->firstMatch($normalized, $symptom['phrases'] ?? [], self::NEGATION_EN)
                    ?? $this->firstMatch($normalized, $symptom['phrases_ar'] ?? [], self::NEGATION_AR);
                if ($match === null) {
                    continue;
                }
                [$position, $length] = $match;
                $start = $map[$position];
                $end = $map[$position + $length - 1] + 1;
                // Keep vowel marks written on the last letter (e.g. "صُداعٍ").
                while ($end < mb_strlen($text) && self::isMark(mb_substr($text, $end, 1))) {
                    $end++;
                }
                $found[$key] = [
                    'key' => $key,
                    'label' => t("ai.symptom.{$key}"),
                    'specialists' => $symptom['specialists'],
                    'red_flag' => $symptom['red_flag'],
                    'quote' => mb_substr($text, $start, $end - $start),
                ];
            }
        }
        return $found;
    }

    // [position, length] of the first phrase found that is not negated, in the normalised text.
    private function firstMatch(string $normalized, array $phrases, string $negation): ?array
    {
        foreach ($phrases as $phrase) {
            $phrase = self::normalize($phrase)[0];
            $offset = 0;
            while (($position = mb_strpos($normalized, $phrase, $offset)) !== false) {
                if (!preg_match($negation, mb_substr($normalized, 0, $position))) {
                    return [$position, mb_strlen($phrase)];
                }
                $offset = $position + 1;
            }
        }
        return null;
    }

    /**
     * Lower-cases and normalises Arabic spelling variants.
     * @return array{0: string, 1: int[]} normalised text and, per normalised character, its index in the original
     */
    public static function normalize(string $text): array
    {
        $replace = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'];
        $normalized = '';
        $map = [];
        foreach (mb_str_split($text) as $index => $original) {
            if (self::isMark($original)) {
                continue;
            }
            // Lower-casing can turn one character into two; both point back to the same original one.
            foreach (mb_str_split(mb_strtolower($original)) as $character) {
                $normalized .= $replace[$character] ?? $character;
                $map[] = $index;
            }
        }
        return [$normalized, $map];
    }

    // Arabic diacritics (harakat, shadda, sukun, superscript alef) and tatweel: no meaning for matching.
    private static function isMark(string $character): bool
    {
        return (bool) preg_match('/^[\x{064B}-\x{065F}\x{0670}\x{0640}]$/u', $character);
    }
}

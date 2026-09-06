<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * A question, reduced to the words worth searching for.
 *
 * Terms are OR-ed, so that a passage matching some of them still ranks; the
 * price of OR is that a word found everywhere — "le", "est", "the" — makes
 * every chunk a match. BM25 gives such words little weight, but little is not
 * nothing, and the words of a question are few. They go, in French and in
 * English, since documentation is written in either and asked about in both.
 */
final class QueryTerms
{
    private const STOPWORDS = [
        // French
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'au', 'aux', 'et', 'ou', 'où', 'en', 'dans',
        'sur', 'sous', 'par', 'pour', 'avec', 'sans', 'ce', 'cet', 'cette', 'ces', 'qui', 'que', 'qu', 'quoi',
        'quel', 'quelle', 'quels', 'quelles', 'est', 'sont', 'été', 'être', 'a', 'ai', 'as', 'ont', 'avoir',
        'fait', 'faire', 'il', 'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'je', 'j', 'tu', 'se', 's', 'ne',
        'pas', 'plus', 'comment', 'combien', 'pourquoi', 'quand', 'si', 'y', 'son', 'sa', 'ses', 'leur', 'leurs',
        'notre', 'nos', 'votre', 'vos', 'mon', 'ma', 'mes', 'c', 'ça', 'cela', 'doit', 'peut', 'faut', 't',
        // English
        'the', 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'for', 'with', 'by', 'from', 'and', 'or', 'is', 'are',
        'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'what', 'which', 'who',
        'how', 'why', 'when', 'where', 'do', 'does', 'did', 'can', 'should', 'we', 'you', 'our', 'your', 'i',
    ];

    /** @return list<string> */
    public static function of(string $question): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($question), $matches);

        $terms = [];
        foreach ($matches[0] as $word) {
            if (mb_strlen($word) > 1 && !in_array($word, self::STOPWORDS, true) && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }

        return $terms;
    }

    /**
     * Words cut back to a stem, for prefix matching: "refunded" searches
     * "rembours…", which reaches "remboursement" and "rembourser" too. Crude —
     * a few French and English endings, nothing more — and kept only if the
     * evaluation says it finds more than it confuses.
     *
     * @param list<string> $terms
     *
     * @return list<string>
     */
    public static function stems(array $terms): array
    {
        $stems = [];
        foreach ($terms as $term) {
            $stem = $term;
            if (mb_strlen($term) > 5) {
                $stem = (string) preg_replace('/(ements?|ations?|ements|ement|ations|ation|ées?|és?|er|ez|es|s|x|ing|ed)$/u', '', $term);
                if (mb_strlen($stem) < 4) {
                    $stem = $term;
                }
            }
            if (!in_array($stem, $stems, true)) {
                $stems[] = $stem;
            }
        }

        return $stems;
    }
}

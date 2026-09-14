<?php
class Deck {
    const RANKS = ['2','3','4','5','6','7','8','9','T','J','Q','K','A'];
    const SUITS = ['S','H','D','C'];

    public static function fresh(): array {
        $cards = [];
        foreach (self::RANKS as $r) {
            foreach (self::SUITS as $s) {
                $cards[] = $r . $s;
            }
        }
        shuffle($cards);
        return $cards;
    }

    public static function rankValue(string $card): int {
        $map = ['2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'T'=>10,'J'=>11,'Q'=>12,'K'=>13,'A'=>14];
        return $map[$card[0]];
    }

    public static function suit(string $card): string {
        return $card[1];
    }

    public static function encode(array $cards): string {
        return implode(',', $cards);
    }

    public static function decode(string $s): array {
        return $s === '' ? [] : explode(',', $s);
    }
}

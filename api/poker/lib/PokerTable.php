<?php
require_once __DIR__ . '/Deck.php';
require_once __DIR__ . '/HandEvaluator.php';

class PokerTable {
    private PDO $pdo;
    private int $tableId;

    public function __construct(PDO $pdo, int $tableId) {
        $this->pdo = $pdo;
        $this->tableId = $tableId;
    }

    private function loadTable(): array {
        $stmt = $this->pdo->prepare('SELECT * FROM poker_tables WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $this->tableId]);
        return $stmt->fetch();
    }

    private function loadPlayers(): array {
        $stmt = $this->pdo->prepare('SELECT * FROM poker_players WHERE table_id = :id ORDER BY seat ASC');
        $stmt->execute([':id' => $this->tableId]);
        $rows = $stmt->fetchAll();
        $bySeat = [];
        foreach ($rows as $r) $bySeat[(int)$r['seat']] = $r;
        return $bySeat;
    }

    private function saveTable(array $t): void {
        $stmt = $this->pdo->prepare(
            'UPDATE poker_tables SET status=:status, small_blind=:sb, big_blind=:bb, dealer_seat=:dealer,
             street=:street, community_cards=:cc, deck=:deck, pot=:pot, current_bet=:cbet, min_raise=:minr,
             current_seat=:cseat, last_aggressor_seat=:lagg, hand_number=:hn, turn_deadline=:td,
             next_hand_at=:nha, last_result=:lr WHERE id=:id'
        );
        $stmt->execute([
            ':status' => $t['status'], ':sb' => $t['small_blind'], ':bb' => $t['big_blind'],
            ':dealer' => $t['dealer_seat'], ':street' => $t['street'], ':cc' => $t['community_cards'],
            ':deck' => $t['deck'], ':pot' => $t['pot'], ':cbet' => $t['current_bet'], ':minr' => $t['min_raise'],
            ':cseat' => $t['current_seat'], ':lagg' => $t['last_aggressor_seat'], ':hn' => $t['hand_number'],
            ':td' => $t['turn_deadline'], ':nha' => $t['next_hand_at'], ':lr' => $t['last_result'],
            ':id' => $this->tableId,
        ]);
    }

    private function savePlayer(array $p): void {
        $stmt = $this->pdo->prepare(
            'UPDATE poker_players SET chips=:chips, hole_cards=:hc, status=:status, current_bet=:cbet,
             total_bet_this_hand=:tbh, has_acted=:acted WHERE id=:id'
        );
        $stmt->execute([
            ':chips' => $p['chips'], ':hc' => $p['hole_cards'], ':status' => $p['status'],
            ':cbet' => $p['current_bet'], ':tbh' => $p['total_bet_this_hand'], ':acted' => $p['has_acted'],
            ':id' => $p['id'],
        ]);
    }

    private function eligibleSeats(array $players): array {
        $seats = [];
        foreach ($players as $seat => $p) {
            if ($p['status'] !== 'out' && $p['status'] !== 'sitting_out') $seats[] = $seat;
        }
        sort($seats);
        return $seats;
    }

    private function dealableSeats(array $players): array {
        $seats = [];
        foreach ($players as $seat => $p) {
            if ($p['status'] !== 'out') $seats[] = $seat;
        }
        sort($seats);
        return $seats;
    }

    private function nextSeatAfter(array $orderedSeats, int $from): ?int {
        if (empty($orderedSeats)) return null;
        $idx = array_search($from, $orderedSeats);
        if ($idx === false) {
            foreach ($orderedSeats as $s) if ($s > $from) return $s;
            return $orderedSeats[0];
        }
        return $orderedSeats[($idx + 1) % count($orderedSeats)];
    }

    public function dealNewHand(): void {
        $this->pdo->beginTransaction();
        $t = $this->loadTable();
        $players = $this->loadPlayers();

        foreach ($players as $seat => $p) {
            if ($p['status'] !== 'out' && $p['status'] !== 'sitting_out' && (int)$p['chips'] === 0) {
                $players[$seat]['status'] = 'out';
            }
        }

        $eligible = $this->dealableSeats($players);
        if (count($eligible) < 2) {
            $t['status'] = 'waiting';
            $this->saveTable($t);
            foreach ($players as $p) $this->savePlayer($p);
            $this->pdo->commit();
            return;
        }

        foreach ($eligible as $seat) {
            $players[$seat]['status'] = 'active';
            $players[$seat]['hole_cards'] = '';
            $players[$seat]['current_bet'] = 0;
            $players[$seat]['total_bet_this_hand'] = 0;
            $players[$seat]['has_acted'] = 0;
        }

        $dealerSeat = (int)$t['dealer_seat'];
        if ($dealerSeat < 0 || !in_array($dealerSeat, $eligible)) {
            $newDealer = $eligible[0];
        } else {
            $newDealer = $this->nextSeatAfter($eligible, $dealerSeat);
        }
        $t['dealer_seat'] = $newDealer;

        $deck = Deck::fresh();
        foreach ($eligible as $seat) {
            $players[$seat]['hole_cards'] = Deck::encode([array_pop($deck), array_pop($deck)]);
        }

        $sb = (int)$t['small_blind'];
        $bb = (int)$t['big_blind'];

        if (count($eligible) === 2) {
            $sbSeat = $newDealer;
            $bbSeat = $this->nextSeatAfter($eligible, $newDealer);
            $firstToActPreflop = $sbSeat;
        } else {
            $sbSeat = $this->nextSeatAfter($eligible, $newDealer);
            $bbSeat = $this->nextSeatAfter($eligible, $sbSeat);
            $firstToActPreflop = $this->nextSeatAfter($eligible, $bbSeat);
        }

        $postBlind = function(&$p, $amount) {
            $pay = min($amount, (int)$p['chips']);
            $p['chips'] -= $pay;
            $p['current_bet'] = $pay;
            $p['total_bet_this_hand'] = $pay;
            if ($p['chips'] === 0) $p['status'] = 'all_in';
            return $pay;
        };
        $pot = 0;
        $pot += $postBlind($players[$sbSeat], $sb);
        $pot += $postBlind($players[$bbSeat], $bb);

        $t['status'] = 'playing';
        $t['street'] = 'preflop';
        $t['community_cards'] = '';
        $t['deck'] = Deck::encode($deck);
        $t['pot'] = $pot;
        $t['current_bet'] = $bb;
        $t['min_raise'] = $bb;
        $t['current_seat'] = $firstToActPreflop;
        $t['last_aggressor_seat'] = $bbSeat;
        $t['hand_number'] = (int)$t['hand_number'] + 1;
        $t['turn_deadline'] = date('Y-m-d H:i:s', time() + 20);
        $t['next_hand_at'] = null;
        $t['last_result'] = null;

        $this->saveTable($t);
        foreach ($players as $p) $this->savePlayer($p);
        $this->pdo->commit();
    }

    private function activePlayersInHand(array $players): array {
        return array_filter($players, fn($p) => in_array($p['status'], ['active', 'all_in']));
    }

    private function canActPlayers(array $players): array {
        return array_filter($players, fn($p) => $p['status'] === 'active');
    }

    public function applyAction(int $seat, string $action, int $amount = 0): array {
        $this->pdo->beginTransaction();
        $t = $this->loadTable();
        $players = $this->loadPlayers();

        if ($t['status'] !== 'playing' || (int)$t['current_seat'] !== $seat || !isset($players[$seat]) || $players[$seat]['status'] !== 'active') {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => 'not_your_turn'];
        }

        $p = $players[$seat];
        $tableBet = (int)$t['current_bet'];

        if ($action === 'fold') {
            $p['status'] = 'folded';
            $p['has_acted'] = 1;
        } elseif ($action === 'check') {
            if ((int)$p['current_bet'] !== $tableBet) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'cannot_check'];
            }
            $p['has_acted'] = 1;
        } elseif ($action === 'call') {
            $owe = $tableBet - (int)$p['current_bet'];
            $pay = max(0, min($owe, (int)$p['chips']));
            $p['chips'] -= $pay;
            $p['current_bet'] += $pay;
            $p['total_bet_this_hand'] += $pay;
            $t['pot'] = (int)$t['pot'] + $pay;
            if ($p['chips'] === 0) $p['status'] = 'all_in';
            $p['has_acted'] = 1;
        } elseif ($action === 'bet' || $action === 'raise') {
            $target = max(0, $amount);
            $target = min($target, (int)$p['current_bet'] + (int)$p['chips']);
            if ($target <= $tableBet && (int)$p['current_bet'] + (int)$p['chips'] > $tableBet) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'raise_too_small'];
            }
            $increase = $target - (int)$p['current_bet'];
            $increase = max(0, min($increase, (int)$p['chips']));
            $p['chips'] -= $increase;
            $p['total_bet_this_hand'] += $increase;
            $p['current_bet'] += $increase;
            $t['pot'] = (int)$t['pot'] + $increase;
            $raiseSize = $p['current_bet'] - $tableBet;
            if ($p['current_bet'] > $tableBet) {
                $t['current_bet'] = $p['current_bet'];
                $t['min_raise'] = max((int)$t['min_raise'], $raiseSize);
                foreach ($players as $s2 => $pp) {
                    if ($s2 !== $seat && $pp['status'] === 'active') $players[$s2]['has_acted'] = 0;
                }
            }
            if ($p['chips'] === 0) $p['status'] = 'all_in';
            $p['has_acted'] = 1;
        } else {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => 'unknown_action'];
        }

        $players[$seat] = $p;
        $this->resolveAfterAction($t, $players);

        $this->saveTable($t);
        foreach ($players as $pl) $this->savePlayer($pl);
        $this->pdo->commit();
        return ['ok' => true];
    }

    private function resolveAfterAction(array &$t, array &$players): void {
        $inHand = $this->activePlayersInHand($players);

        if (count($inHand) <= 1) {
            $this->finishHandUncontested($t, $players, $inHand);
            return;
        }

        $canAct = $this->canActPlayers($players);
        $roundDone = true;
        foreach ($canAct as $p) {
            if (!$p['has_acted'] || (int)$p['current_bet'] !== (int)$t['current_bet']) {
                $roundDone = false;
                break;
            }
        }

        if (!$roundDone) {
            $eligible = $this->eligibleSeats($players);
            $next = $this->nextSeatAfter($eligible, (int)$t['current_seat']);
            $guard = 0;
            while ($players[$next]['status'] !== 'active' && $guard < 20) {
                $next = $this->nextSeatAfter($eligible, $next);
                $guard++;
            }
            $t['current_seat'] = $next;
            $t['turn_deadline'] = date('Y-m-d H:i:s', time() + 20);
            return;
        }

        if (count($canAct) <= 1) {
            $this->runOutBoardAndShowdown($t, $players);
            return;
        }

        $this->advanceStreet($t, $players);
    }

    private function advanceStreet(array &$t, array &$players): void {
        $deck = Deck::decode($t['deck']);
        $cc = Deck::decode($t['community_cards']);

        if ($t['street'] === 'preflop') {
            $cc = array_merge($cc, [array_pop($deck), array_pop($deck), array_pop($deck)]);
            $t['street'] = 'flop';
        } elseif ($t['street'] === 'flop') {
            $cc[] = array_pop($deck);
            $t['street'] = 'turn';
        } elseif ($t['street'] === 'turn') {
            $cc[] = array_pop($deck);
            $t['street'] = 'river';
        } else {
            $this->showdown($t, $players);
            return;
        }

        $t['deck'] = Deck::encode($deck);
        $t['community_cards'] = Deck::encode($cc);
        $t['current_bet'] = 0;
        $t['min_raise'] = (int)$t['big_blind'];

        foreach ($players as $seat => $p) {
            if ($p['status'] === 'active') {
                $players[$seat]['current_bet'] = 0;
                $players[$seat]['has_acted'] = 0;
            }
        }

        $eligible = $this->eligibleSeats($players);
        $start = $this->nextSeatAfter($eligible, (int)$t['dealer_seat']);
        $guard = 0;
        while ($players[$start]['status'] !== 'active' && $guard < 20) {
            $start = $this->nextSeatAfter($eligible, $start);
            $guard++;
        }
        if ($players[$start]['status'] !== 'active') {
            $this->runOutBoardAndShowdown($t, $players);
            return;
        }
        $t['current_seat'] = $start;
        $t['turn_deadline'] = date('Y-m-d H:i:s', time() + 20);
    }

    private function runOutBoardAndShowdown(array &$t, array &$players): void {
        $deck = Deck::decode($t['deck']);
        $cc = Deck::decode($t['community_cards']);
        while (count($cc) < 5 && count($deck) > 0) {
            $cc[] = array_pop($deck);
        }
        $t['deck'] = Deck::encode($deck);
        $t['community_cards'] = Deck::encode($cc);
        $this->showdown($t, $players);
    }

    private function finishHandUncontested(array &$t, array &$players, array $inHand): void {
        $winnerSeat = array_key_first($inHand);
        $pot = (int)$t['pot'];
        $players[$winnerSeat]['chips'] += $pot;

        $t['status'] = 'showdown';
        $t['current_seat'] = -1;
        $t['pot'] = 0;
        $t['next_hand_at'] = date('Y-m-d H:i:s', time() + 4);
        $t['last_result'] = json_encode([
            'type' => 'uncontested',
            'winners' => [['seat' => $winnerSeat, 'name' => $players[$winnerSeat]['player_name'], 'amount' => $pot]],
        ]);
    }

    private function showdown(array &$t, array &$players): void {
        $inHand = $this->activePlayersInHand($players);
        $cc = Deck::decode($t['community_cards']);

        $hands = [];
        foreach ($inHand as $seat => $p) {
            $seven = array_merge(Deck::decode($p['hole_cards']), $cc);
            $hands[$seat] = HandEvaluator::best($seven);
        }

        $contributions = [];
        foreach ($players as $seat => $p) {
            if ((int)$p['total_bet_this_hand'] > 0) $contributions[$seat] = (int)$p['total_bet_this_hand'];
        }

        $levels = [];
        foreach ($inHand as $seat => $p) $levels[] = (int)$p['total_bet_this_hand'];
        $levels = array_values(array_unique($levels));
        sort($levels);

        $prevLevel = 0;
        $winnersOut = [];
        foreach ($levels as $level) {
            $potAmount = 0;
            foreach ($contributions as $seat => $amt) {
                $potAmount += max(0, min($amt, $level) - $prevLevel);
            }
            $eligibleSeats = array_keys(array_filter($inHand, fn($p, $s) => (int)$p['total_bet_this_hand'] >= $level, ARRAY_FILTER_USE_BOTH));

            if ($potAmount > 0 && count($eligibleSeats) > 0) {
                $bestVal = null;
                foreach ($eligibleSeats as $s) {
                    if ($bestVal === null || HandEvaluator::cmp($hands[$s], $bestVal) > 0) $bestVal = $hands[$s];
                }
                $winners = array_filter($eligibleSeats, fn($s) => HandEvaluator::cmp($hands[$s], $bestVal) === 0);
                $share = intdiv($potAmount, count($winners));
                $remainder = $potAmount - $share * count($winners);
                $i = 0;
                foreach ($winners as $s) {
                    $amt = $share + ($i === 0 ? $remainder : 0);
                    $players[$s]['chips'] += $amt;
                    $winnersOut[] = ['seat' => $s, 'name' => $players[$s]['player_name'], 'amount' => $amt, 'category' => HandEvaluator::categoryName($hands[$s][0])];
                    $i++;
                }
            }
            $prevLevel = $level;
        }

        $reveal = [];
        foreach ($inHand as $seat => $p) {
            $reveal[] = ['seat' => $seat, 'name' => $p['player_name'], 'cards' => $p['hole_cards'], 'category' => HandEvaluator::categoryName($hands[$seat][0])];
        }

        $t['status'] = 'showdown';
        $t['current_seat'] = -1;
        $t['pot'] = 0;
        $t['next_hand_at'] = date('Y-m-d H:i:s', time() + 7);
        $t['last_result'] = json_encode(['type' => 'showdown', 'winners' => $winnersOut, 'reveal' => $reveal]);
    }

    public function checkTimeoutAndAdvance(): void {
        $t = $this->loadTable();
        if ($t === false) return;

        if ($t['status'] === 'playing' && $t['turn_deadline'] !== null && strtotime($t['turn_deadline']) < time()) {
            $seat = (int)$t['current_seat'];
            $players = $this->loadPlayers();
            if (isset($players[$seat]) && $players[$seat]['status'] === 'active') {
                $canCheck = (int)$players[$seat]['current_bet'] === (int)$t['current_bet'];
                $this->applyAction($seat, $canCheck ? 'check' : 'fold');
            }
            return;
        }

        if ($t['status'] === 'showdown' && $t['next_hand_at'] !== null && strtotime($t['next_hand_at']) < time()) {
            $this->dealNewHand();
        }
    }
}

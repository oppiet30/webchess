<?php

declare(strict_types=1);

namespace WebChess\Game;

/**
 * Faithful orchestration wrapper around the legacy chessdb.php / move.php /
 * undo.php functions. Parameterizes the $_POST / $_SESSION coupling and
 * returns the resulting game state as a plain array, so callers no longer
 * need to rely on globals for the board/history/flags.
 *
 * CSRF + participant checks stay in the calling entrypoint (same as
 * MainmenuController does for mainmenu.php).
 */
final class GameService
{
    /**
     * Applies the full state-change chain that chess.php performs when a POST
     * arrives (undo / promotion / move / incomplete-promotion detection),
     * delegating to the legacy global functions.
     *
     * @param array<string, mixed> $post  The subset of $_POST fields that
     *                                    chess.php reads (fromRow, toRow, …).
     * @return array{board: array, history: array, numMoves: int, playersColor: string, isInCheck: bool, isPromoting: bool, isUndoing: bool, isCheckMate: bool, isUndoRequested: bool, isDrawRequested: bool, isGameOver: bool, statusMessage: string}
     */
    public function applyStateChange(int $gameId, int $playerId, bool $isSharedPC, array $post): array
    {
        $savedSession = $_SESSION;
        $savedPost    = $_POST;

        try {
            /* Set up the context the legacy functions expect. */
            $_SESSION['gameID']      = $gameId;
            $_SESSION['playerID']    = $playerId;
            $_SESSION['isSharedPC']  = $isSharedPC;
            $_SESSION['nick']        = $savedSession['nick'] ?? '';
            $_SESSION['lastInputTime'] = $savedSession['lastInputTime'] ?? \time();
            $_SESSION['playerName']  = $savedSession['playerName'] ?? '';
            $_SESSION['firstName']   = $savedSession['firstName'] ?? '';
            $_SESSION['lastName']    = $savedSession['lastName'] ?? '';
            $_POST = $post;

            /* Import globals the legacy functions read / write. */
            global $CFG_TABLE;
            global $CFG_USEEMAILNOTIFICATION;
            global $board, $history, $numMoves;
            global $isInCheck, $playersColor;
            global $isUndoRequested, $isDrawRequested, $isUndoing;
            global $isGameOver, $isCheckMate, $statusMessage;
            global $isPromoting;

            /* Replicate chess.php lines 77-83. */
            $isInCheck = (isset($post['isInCheck']) && ($post['isInCheck'] == 'true'));
            $isCheckMate = false;
            $isPromoting = false;
            $isUndoing   = false;
            $statusMessage = '';

            \loadHistory();
            \loadGame();
            \processMessages();

            /* ---- chess.php lines 85-161: state-change chain ---- */
            /* PHPStan cannot see that processMessages() (chessdb.php) mutates
               the $isUndoing global through this closure scope; the legacy
               pattern is to reset it here (chess.php line 80) and re-read it
               after the call, so annotate the always-false inference. */
            /* @phpstan-ignore if.alwaysFalse */
            if ($isUndoing) {
                \doUndo();
                \saveGame();
            } elseif (
                !empty($post['promotion'])
                && isset($post['toRow']) && ('' !== $post['toRow'])
                && isset($post['toCol']) && ('' !== $post['toCol'])
            ) {
                if (\isValidPromotionServer((int) $post['toRow'], (int) $post['toCol'], (int) $post['promotion'])) {
                    \savePromotion();
                    $board[(int) $post['toRow']][(int) $post['toCol']]
                        = ((int) $post['promotion']) | ($board[(int) $post['toRow']][(int) $post['toCol']] & \BLACK);
                    \saveGame();
                }
            } elseif (
                isset($post['fromRow']) && isset($post['fromCol'])
                && isset($post['toRow']) && isset($post['toCol'])
                && ('' !== $post['fromRow']) && ('' !== $post['fromCol'])
                && ('' !== $post['toRow']) && ('' !== $post['toCol'])
            ) {
                $tmpIsValid = true;

                if (($numMoves == -1) || ($numMoves % 2 == 1)) {
                    if (($board[(int) $post['fromRow']][(int) $post['fromCol']] & \BLACK) != 0
                        || $board[(int) $post['fromRow']][(int) $post['fromCol']] == 0
                    ) {
                        $tmpIsValid = false;
                    }
                } else {
                    if (($board[(int) $post['fromRow']][(int) $post['fromCol']] & \BLACK) != \BLACK
                        || $board[(int) $post['fromRow']][(int) $post['fromCol']] == 0
                    ) {
                        $tmpIsValid = false;
                    }
                }

                /* castling validation */
                if ($tmpIsValid
                    && ($board[(int) $post['fromRow']][(int) $post['fromCol']] & \COLOR_MASK) == \KING
                    && $post['fromRow'] == $post['toRow']
                    && \abs((int) $post['toCol'] - (int) $post['fromCol']) == 2
                ) {
                    if (!\isValidCastlingServer((int) $post['fromRow'], (int) $post['fromCol'], (int) $post['toRow'], (int) $post['toCol'])) {
                        $tmpIsValid = false;
                    }
                }

                /* en-passant validation */
                if ($tmpIsValid
                    && ($board[(int) $post['fromRow']][(int) $post['fromCol']] & \COLOR_MASK) == \PAWN
                    && $post['toCol'] != $post['fromCol']
                    && $board[(int) $post['toRow']][(int) $post['toCol']] == 0
                ) {
                    if (!\isValidEnPassantServer((int) $post['fromRow'], (int) $post['fromCol'], (int) $post['toRow'], (int) $post['toCol'])) {
                        $tmpIsValid = false;
                    }
                }

                if ($tmpIsValid) {
                    \saveHistory();
                    \doMove();
                    \saveGame();
                }
            } elseif (
                $numMoves >= 0
                && isset($history[$numMoves]['curPiece'])
                && $history[$numMoves]['curPiece'] === 'pawn'
                && $history[$numMoves]['promotedTo'] === null
            ) {
                if ($history[$numMoves]['toRow'] == 7 || $history[$numMoves]['toRow'] == 0) {
                    $isPromoting = true;
                }
            }

            return [
                'board'            => $board,
                'history'          => $history,
                'numMoves'         => $numMoves,
                'playersColor'     => $playersColor,
                'isInCheck'        => $isInCheck,
                'isPromoting'      => $isPromoting,
                'isUndoing'        => $isUndoing,
                'isCheckMate'      => $isCheckMate,
                'isUndoRequested'  => $isUndoRequested,
                'isDrawRequested'  => $isDrawRequested,
                'isGameOver'       => $isGameOver,
                'statusMessage'    => $statusMessage,
            ];
        } finally {
            $_SESSION = $savedSession;
            $_POST    = $savedPost;
        }
    }
}

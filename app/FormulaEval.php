<?php
/**
 * app/FormulaEval.php — Valutatore sicuro di espressioni aritmetiche (v1.7.95)
 *
 * Le formule di calcolo costo/FTE sono modificabili da interfaccia: per non
 * eseguire mai codice arbitrario, le espressioni sono analizzate con un parser
 * a discesa ricorsiva che accetta soltanto numeri, nomi di variabile note,
 * gli operatori + - * / e le parentesi tonde. Nessun uso di eval().
 *
 * Grammatica:
 *   expr   := term (('+' | '-') term)*
 *   term   := factor (('*' | '/') factor)*
 *   factor := ('+' | '-') factor | number | name | '(' expr ')'
 */
final class FormulaEval
{
    private string $s;
    private int $i = 0;
    private array $vars;

    private function __construct(string $expr, array $vars)
    {
        $this->s = $expr;
        $this->vars = $vars;
    }

    /**
     * Valuta l'espressione con le variabili date.
     * @throws InvalidArgumentException se la sintassi non è valida o usa nomi sconosciuti
     */
    public static function evaluate(string $expr, array $vars): float
    {
        $p = new self($expr, $vars);
        $v = $p->parseExpr();
        $p->skipWs();
        if ($p->i < strlen($p->s)) {
            throw new InvalidArgumentException('Carattere non atteso in posizione ' . ($p->i + 1) . '.');
        }
        if (!is_finite($v)) throw new InvalidArgumentException('Risultato non valido (divisione per zero?).');
        return $v;
    }

    /** Verifica la sintassi e i nomi usati; ritorna null se valida, altrimenti il messaggio d'errore. */
    public static function validate(string $expr, array $allowedNames): ?string
    {
        $vars = [];
        foreach ($allowedNames as $n) $vars[$n] = 1.0;
        try { self::evaluate($expr, $vars); return null; }
        catch (Throwable $e) { return $e->getMessage(); }
    }

    private function skipWs(): void
    {
        while ($this->i < strlen($this->s) && ctype_space($this->s[$this->i])) $this->i++;
    }

    private function parseExpr(): float
    {
        $v = $this->parseTerm();
        while (true) {
            $this->skipWs();
            $c = $this->s[$this->i] ?? '';
            if ($c === '+') { $this->i++; $v += $this->parseTerm(); }
            elseif ($c === '-') { $this->i++; $v -= $this->parseTerm(); }
            else return $v;
        }
    }

    private function parseTerm(): float
    {
        $v = $this->parseFactor();
        while (true) {
            $this->skipWs();
            $c = $this->s[$this->i] ?? '';
            if ($c === '*') { $this->i++; $v *= $this->parseFactor(); }
            elseif ($c === '/') {
                $this->i++;
                $d = $this->parseFactor();
                if ($d == 0.0) throw new InvalidArgumentException('Divisione per zero.');
                $v /= $d;
            } else return $v;
        }
    }

    private function parseFactor(): float
    {
        $this->skipWs();
        $c = $this->s[$this->i] ?? '';
        if ($c === '') throw new InvalidArgumentException('Espressione incompleta.');
        if ($c === '+') { $this->i++; return $this->parseFactor(); }
        if ($c === '-') { $this->i++; return -$this->parseFactor(); }
        if ($c === '(') {
            $this->i++;
            $v = $this->parseExpr();
            $this->skipWs();
            if (($this->s[$this->i] ?? '') !== ')') throw new InvalidArgumentException('Parentesi non chiusa.');
            $this->i++;
            return $v;
        }
        if (ctype_digit($c) || $c === '.') {
            $start = $this->i;
            while ($this->i < strlen($this->s) && (ctype_digit($this->s[$this->i]) || $this->s[$this->i] === '.')) $this->i++;
            $num = substr($this->s, $start, $this->i - $start);
            if (!is_numeric($num)) throw new InvalidArgumentException("Numero non valido: $num");
            return (float)$num;
        }
        if (ctype_alpha($c) || $c === '_') {
            $start = $this->i;
            while ($this->i < strlen($this->s) && (ctype_alnum($this->s[$this->i]) || $this->s[$this->i] === '_')) $this->i++;
            $name = substr($this->s, $start, $this->i - $start);
            if (!array_key_exists($name, $this->vars)) throw new InvalidArgumentException("Variabile sconosciuta: $name");
            return (float)$this->vars[$name];
        }
        throw new InvalidArgumentException("Carattere non ammesso: '$c'");
    }
}

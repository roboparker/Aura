<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function `SEARCH_VECTOR_MATCH(vector, query)` → Postgres
 * `vector @@ websearch_to_tsquery('english', query)`. Returns a boolean
 * — Doctrine doesn't expose `@@` directly, so this wraps it as a normal
 * function call.
 *
 * websearch_to_tsquery (vs. to_tsquery) accepts user-typed strings —
 * including quoted phrases and `-term` exclusions — and never raises
 * on invalid syntax, so we don't need to sanitise input ourselves.
 */
final class SearchVectorMatch extends FunctionNode
{
    private Node $vectorExpr;
    private Node $queryExpr;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->vectorExpr = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->queryExpr = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            "(%s @@ websearch_to_tsquery('english', %s))",
            $this->vectorExpr->dispatch($sqlWalker),
            $this->queryExpr->dispatch($sqlWalker),
        );
    }
}

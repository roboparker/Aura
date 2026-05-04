<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function `SEARCH_VECTOR_RANK(vector, query)` → Postgres
 * `ts_rank(vector, websearch_to_tsquery('english', query))`. Used by
 * the task search filter to ORDER BY relevance when a search param is
 * present — higher rank surfaces first.
 */
final class SearchVectorRank extends FunctionNode
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
            "ts_rank(%s, websearch_to_tsquery('english', %s))",
            $this->vectorExpr->dispatch($sqlWalker),
            $this->queryExpr->dispatch($sqlWalker),
        );
    }
}

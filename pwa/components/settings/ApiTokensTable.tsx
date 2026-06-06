import { useCallback, useEffect, useState } from "react";
import { Plus } from "lucide-react";
import {
  fetchApiTokens,
  revokeApiToken,
  type ApiTokenRow,
} from "@/lib/apiTokens";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import CreateApiTokenDialog from "./CreateApiTokenDialog";

const formatDate = (iso: string | null): string =>
  iso ? new Date(iso).toLocaleDateString() : "—";

/**
 * Personal access token manager for the Settings → API tokens panel.
 * Lists the user's tokens, opens the create dialog (one-time plaintext
 * reveal), and revokes with a confirm.
 */
const ApiTokensTable = () => {
  const [tokens, setTokens] = useState<ApiTokenRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setTokens(await fetchApiTokens());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tokens.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const revoke = async (token: ApiTokenRow) => {
    if (!window.confirm(`Revoke token "${token.name}"? Any client using it will stop working.`)) {
      return;
    }
    try {
      await revokeApiToken(token["@id"]);
      setTokens((prev) => prev.filter((t) => t["@id"] !== token["@id"]));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to revoke token.");
    }
  };

  return (
    <div className="space-y-3" data-testid="api-tokens">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Use these to authenticate Aura&apos;s REST + MCP APIs as you.
        </p>
        <Button
          type="button"
          size="sm"
          onClick={() => setCreateOpen(true)}
          data-testid="api-token-generate"
        >
          <Plus className="mr-1 h-3.5 w-3.5" /> Generate token
        </Button>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : tokens.length === 0 ? (
        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
          No tokens yet. Generate one to call the API as you.
        </p>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Scopes</TableHead>
                <TableHead>Created</TableHead>
                <TableHead>Last used</TableHead>
                <TableHead className="text-right">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tokens.map((t) => (
                <TableRow key={t["@id"]} data-testid="api-token-row">
                  <TableCell className="font-medium">{t.name}</TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1">
                      {t.scopes.length === 0 ? (
                        <Badge variant="secondary">all</Badge>
                      ) : (
                        t.scopes.map((s) => (
                          <Badge key={s} variant="outline" className="font-mono text-xs">
                            {s}
                          </Badge>
                        ))
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {formatDate(t.createdAt)}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {t.lastUsedAt ? formatDate(t.lastUsedAt) : "never"}
                  </TableCell>
                  <TableCell className="text-right">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-destructive hover:text-destructive"
                      onClick={() => void revoke(t)}
                      data-testid="api-token-revoke"
                    >
                      Revoke
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <CreateApiTokenDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreated={() => void load()}
      />
    </div>
  );
};

export default ApiTokensTable;

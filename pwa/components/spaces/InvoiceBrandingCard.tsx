import { useRef, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

interface MediaObjectResponse {
  "@id": string;
  variantUrls?: { thumb?: string; profile?: string };
}

export interface BrandingSpace {
  id: string;
  invoiceLogo?: { "@id": string; variantUrls?: { thumb?: string; profile?: string } } | null;
  invoiceTerms?: string | null;
  invoiceNumberPrefix?: string | null;
  invoiceNumberNext?: number | null;
}

/**
 * Invoice branding (#669) — space-admin card on the space settings page:
 * logo (shown on the PDF + public invoice/estimate pages), default
 * terms/footer text, and the invoice number prefix + next counter.
 */
const InvoiceBrandingCard = ({
  space,
  onSaved,
}: {
  space: BrandingSpace;
  onSaved: () => void | Promise<void>;
}) => {
  const [terms, setTerms] = useState(space.invoiceTerms ?? "");
  const [prefix, setPrefix] = useState(space.invoiceNumberPrefix ?? "");
  const [nextNumber, setNextNumber] = useState(
    space.invoiceNumberNext !== null && space.invoiceNumberNext !== undefined
      ? String(space.invoiceNumberNext)
      : "",
  );
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [message, setMessage] = useState<{ text: string; kind: "success" | "error" } | null>(
    null,
  );
  const fileRef = useRef<HTMLInputElement | null>(null);

  const patchSpace = async (body: Record<string, unknown>): Promise<boolean> => {
    const res = await fetch(`${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setMessage({
        text: data["hydra:description"] || data.detail || data.error || "Failed to save.",
        kind: "error",
      });
      return false;
    }
    return true;
  };

  const saveText = async () => {
    setSaving(true);
    setMessage(null);
    const parsedNext = nextNumber.trim() === "" ? null : parseInt(nextNumber, 10);
    if (parsedNext !== null && (!Number.isFinite(parsedNext) || parsedNext < 1)) {
      setMessage({ text: "Next number must be a positive integer.", kind: "error" });
      setSaving(false);
      return;
    }
    const ok = await patchSpace({
      invoiceTerms: terms.trim() === "" ? null : terms,
      invoiceNumberPrefix: prefix.trim() === "" ? null : prefix.trim(),
      invoiceNumberNext: parsedNext,
    });
    if (ok) {
      setMessage({ text: "Branding saved.", kind: "success" });
      await onSaved();
    }
    setSaving(false);
  };

  const uploadLogo = async (file: File) => {
    setUploading(true);
    setMessage(null);
    try {
      const form = new FormData();
      form.append("file", file);
      const uploadRes = await fetch(`${ENTRYPOINT}/media-objects`, {
        method: "POST",
        credentials: "include",
        body: form,
      });
      if (!uploadRes.ok) {
        const data = await uploadRes.json().catch(() => ({}));
        throw new Error(data.detail || data.error || "Upload failed.");
      }
      const media = (await uploadRes.json()) as MediaObjectResponse;
      if (await patchSpace({ invoiceLogo: media["@id"] })) {
        setMessage({ text: "Logo updated.", kind: "success" });
        await onSaved();
      }
    } catch (e) {
      setMessage({
        text: e instanceof Error ? e.message : "Upload failed.",
        kind: "error",
      });
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  };

  const removeLogo = async () => {
    setMessage(null);
    if (await patchSpace({ invoiceLogo: null })) {
      setMessage({ text: "Logo removed.", kind: "success" });
      await onSaved();
    }
  };

  const logoUrl = space.invoiceLogo?.variantUrls?.profile ?? null;

  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-4">
        <div>
          <h2 className="font-semibold">Invoice branding</h2>
          <p className="text-sm text-muted-foreground">
            Logo, default terms, and numbering used on this space&apos;s invoices,
            estimates, and their public pages.
          </p>
        </div>

        {message && (
          <Alert variant={message.kind === "error" ? "destructive" : "default"}>
            <AlertDescription>{message.text}</AlertDescription>
          </Alert>
        )}

        <div className="flex items-center gap-4">
          {logoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={logoUrl}
              alt="Invoice logo"
              className="h-14 w-auto max-w-48 rounded border object-contain p-1"
            />
          ) : (
            <div className="flex h-14 w-28 items-center justify-center rounded border border-dashed text-xs text-muted-foreground">
              No logo
            </div>
          )}
          <div className="flex items-center gap-2">
            <input
              ref={fileRef}
              type="file"
              accept="image/*"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void uploadLogo(file);
              }}
            />
            <Button
              variant="outline"
              size="sm"
              disabled={uploading}
              onClick={() => fileRef.current?.click()}
            >
              {uploading ? "Uploading…" : logoUrl ? "Replace logo" : "Upload logo"}
            </Button>
            {logoUrl && (
              <Button variant="ghost" size="sm" onClick={() => void removeLogo()}>
                Remove
              </Button>
            )}
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="brand-prefix">Invoice number prefix</Label>
            <Input
              id="brand-prefix"
              value={prefix}
              onChange={(e) => setPrefix(e.target.value)}
              placeholder="INV-"
              maxLength={20}
            />
            <p className="text-xs text-muted-foreground">
              e.g. &quot;ACME-&quot; → ACME-0042. Blank keeps the default INV-.
            </p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="brand-next">Next invoice number</Label>
            <Input
              id="brand-next"
              type="number"
              min={1}
              value={nextNumber}
              onChange={(e) => setNextNumber(e.target.value)}
              placeholder="auto"
            />
            <p className="text-xs text-muted-foreground">
              Blank continues from the count of issued invoices.
            </p>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="brand-terms">Default terms / footer</Label>
          <Textarea
            id="brand-terms"
            value={terms}
            onChange={(e) => setTerms(e.target.value)}
            rows={3}
            maxLength={2000}
            placeholder="Payment due within 30 days. Late payments accrue 1.5% monthly interest."
          />
          <p className="text-xs text-muted-foreground">
            Printed at the bottom of every invoice PDF and public page.
          </p>
        </div>

        <div className="flex justify-end">
          <Button size="sm" onClick={() => void saveText()} disabled={saving}>
            {saving ? "Saving…" : "Save branding"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
};

export default InvoiceBrandingCard;

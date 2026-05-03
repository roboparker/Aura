import { useRef, useState } from "react";
import {
  FileArchive,
  FileImage,
  FileText,
  File as FileIcon,
  Trash2,
  Upload,
} from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { cn } from "@/lib/utils";

export interface Attachment {
  "@id": string;
  id: string;
  originalName: string;
  mimeType: string;
  byteSize: number;
  variantUrls: { original?: string };
}

interface AttachmentsPanelProps {
  taskTitle: string;
  attachments: Attachment[];
  /** Reflects "I can delete attachments I uploaded, OR all attachments
   *  if I'm the task owner". Server is the source of truth — the actual
   *  PATCH may still 422 if the rule changes server-side. */
  canDeleteAll: boolean;
  /** Adds a freshly-uploaded MediaObject IRI to the task's attachments
   *  array. Multiple files are uploaded in series so the parent only
   *  has to merge one IRI at a time. */
  onAttach: (mediaObjectIri: string) => Promise<void>;
  /** Removes an attachment IRI from the task's attachments. The
   *  underlying MediaObject row stays in the DB — orphan cleanup is a
   *  separate ticket (cheap files, complex rules). */
  onDetach: (attachment: Attachment) => Promise<void>;
}

const SUPPORTED_MIMES = [
  "image/jpeg",
  "image/png",
  "image/webp",
  "image/gif",
  "application/pdf",
  "text/plain",
  "text/markdown",
  "text/csv",
  "application/zip",
  "application/json",
];
const ACCEPT_ATTRIBUTE = SUPPORTED_MIMES.join(",");
const MAX_BYTES = 10 * 1024 * 1024;

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const iconFor = (mime: string) => {
  if (mime.startsWith("image/")) return FileImage;
  if (mime === "application/zip") return FileArchive;
  if (mime.startsWith("text/") || mime === "application/json" || mime === "application/pdf")
    return FileText;
  return FileIcon;
};

const AttachmentsPanel = ({
  taskTitle,
  attachments,
  canDeleteAll,
  onAttach,
  onDetach,
}: AttachmentsPanelProps) => {
  const [error, setError] = useState<string | null>(null);
  const [busyCount, setBusyCount] = useState(0);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const uploadOne = async (file: File): Promise<string | null> => {
    if (file.size > MAX_BYTES) {
      throw new Error(`${file.name} is larger than 10 MB.`);
    }
    if (!SUPPORTED_MIMES.includes(file.type)) {
      throw new Error(`${file.name}: unsupported type "${file.type || "unknown"}".`);
    }
    const form = new FormData();
    form.append("file", file);
    form.append("kind", "attachment");
    const res = await fetch(`${ENTRYPOINT}/media-objects`, {
      method: "POST",
      credentials: "include",
      body: form,
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(
        data.detail || data.description || data.error || `Upload failed (${res.status}).`,
      );
    }
    const media = (await res.json()) as { "@id": string };
    return media["@id"];
  };

  const handleFiles = async (files: FileList | File[]) => {
    const list = Array.from(files);
    if (list.length === 0) return;
    setBusyCount((n) => n + list.length);
    setError(null);
    for (const file of list) {
      try {
        const iri = await uploadOne(file);
        if (iri) await onAttach(iri);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Upload failed.");
      } finally {
        setBusyCount((n) => Math.max(0, n - 1));
      }
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      void handleFiles(e.target.files);
    }
    // Reset so the same file can be picked again immediately.
    e.target.value = "";
  };

  return (
    <div className="space-y-3" data-testid="attachments-panel">
      {error && (
        <Alert variant="destructive" data-testid="attachments-error">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {attachments.length > 0 && (
        <ul className="space-y-1">
          {attachments.map((file) => {
            const Icon = iconFor(file.mimeType);
            const url = file.variantUrls.original;
            return (
              <li
                key={file["@id"]}
                className="flex items-center gap-2 text-sm rounded-md border bg-card px-3 py-2"
                data-testid="attachment-item"
              >
                <Icon className="h-4 w-4 text-muted-foreground shrink-0" />
                {url ? (
                  <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium truncate hover:underline"
                    data-testid="attachment-link"
                  >
                    {file.originalName}
                  </a>
                ) : (
                  <span className="font-medium truncate">{file.originalName}</span>
                )}
                <span className="text-xs text-muted-foreground shrink-0">
                  {formatBytes(file.byteSize)}
                </span>
                {canDeleteAll && (
                  <button
                    type="button"
                    onClick={() => void onDetach(file)}
                    aria-label={`Remove ${file.originalName}`}
                    className="ml-auto text-destructive hover:text-destructive/80 p-0.5"
                    data-testid="attachment-delete"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                )}
              </li>
            );
          })}
        </ul>
      )}

      <div
        onDragEnter={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragOver={(e) => e.preventDefault()}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          if (e.dataTransfer.files) void handleFiles(e.dataTransfer.files);
        }}
        className={cn(
          "rounded-md border border-dashed text-sm text-center px-3 py-4 transition-colors",
          dragOver
            ? "border-primary bg-primary/5 text-foreground"
            : "border-input text-muted-foreground",
        )}
        data-testid="attachment-dropzone"
      >
        <Upload className="h-4 w-4 mx-auto mb-1" aria-hidden="true" />
        <p>
          Drop files to attach to{" "}
          <span className="font-medium text-foreground">"{taskTitle}"</span>, or{" "}
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            className="text-cyan-700 hover:underline"
            data-testid="attachment-pick"
          >
            choose from your computer
          </button>
          .
        </p>
        <p className="text-xs mt-1">
          Up to 10 MB. Images, PDFs, text, CSV, JSON, ZIP.
        </p>
        {busyCount > 0 && (
          <p className="text-xs mt-2" data-testid="attachment-uploading">
            Uploading {busyCount} file{busyCount === 1 ? "" : "s"}…
          </p>
        )}
      </div>

      <input
        ref={fileInputRef}
        type="file"
        multiple
        accept={ACCEPT_ATTRIBUTE}
        onChange={handleInputChange}
        className="hidden"
        data-testid="attachment-file-input"
      />
    </div>
  );
};

export default AttachmentsPanel;

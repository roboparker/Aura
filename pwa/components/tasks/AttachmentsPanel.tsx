import { useEffect, useRef, useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  FileArchive,
  FileText,
  File as FileIcon,
  Trash2,
  Upload,
  X,
} from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  ATTACHMENT_SUPPORTED_MIMES,
  uploadAttachmentFile,
} from "@/lib/attachments";
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

const ACCEPT_ATTRIBUTE = ATTACHMENT_SUPPORTED_MIMES.join(",");

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const iconFor = (mime: string) => {
  if (mime === "application/zip") return FileArchive;
  if (mime.startsWith("text/") || mime === "application/json" || mime === "application/pdf")
    return FileText;
  return FileIcon;
};

const isImage = (mime: string) => mime.startsWith("image/");

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

  // Index into `imageAttachments` (computed below) of the image currently
  // shown in the lightbox; null = closed. We store the index rather than the
  // attachment itself so arrow-key cycling stays in sync if the upstream
  // attachments list mutates while open.
  const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);
  const imageAttachments = attachments.filter((a) => isImage(a.mimeType));

  useEffect(() => {
    if (lightboxIndex === null) return;
    if (imageAttachments.length === 0) {
      setLightboxIndex(null);
      return;
    }
    const total = imageAttachments.length;
    const handler = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setLightboxIndex(null);
      } else if (e.key === "ArrowRight") {
        setLightboxIndex((i) => (i === null ? null : (i + 1) % total));
      } else if (e.key === "ArrowLeft") {
        setLightboxIndex((i) => (i === null ? null : (i - 1 + total) % total));
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [lightboxIndex, imageAttachments.length]);

  const handleFiles = async (files: FileList | File[]) => {
    const list = Array.from(files);
    if (list.length === 0) return;
    setBusyCount((n) => n + list.length);
    setError(null);
    for (const file of list) {
      try {
        const iri = await uploadAttachmentFile(file);
        await onAttach(iri);
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
            const url = file.variantUrls.original;
            if (isImage(file.mimeType)) {
              const imageIndex = imageAttachments.indexOf(file);
              return (
                <li
                  key={file["@id"]}
                  className="flex items-center gap-2 text-sm rounded-md border bg-card px-3 py-2"
                  data-testid="attachment-item"
                  data-attachment-kind="image"
                >
                  {url ? (
                    <button
                      type="button"
                      onClick={() => setLightboxIndex(imageIndex)}
                      className="shrink-0 rounded overflow-hidden border bg-muted focus:outline-none focus:ring-2 focus:ring-ring"
                      aria-label={`Preview ${file.originalName}`}
                      data-testid="attachment-thumbnail"
                    >
                      <img
                        src={url}
                        alt=""
                        className="h-10 w-10 object-cover"
                      />
                    </button>
                  ) : (
                    <span className="h-10 w-10 shrink-0 rounded border bg-muted" />
                  )}
                  {url ? (
                    <button
                      type="button"
                      onClick={() => setLightboxIndex(imageIndex)}
                      className="font-medium truncate hover:underline text-left"
                      data-testid="attachment-link"
                    >
                      {file.originalName}
                    </button>
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
            }
            const Icon = iconFor(file.mimeType);
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

      {lightboxIndex !== null && imageAttachments[lightboxIndex] && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={`Preview: ${imageAttachments[lightboxIndex].originalName}`}
          onClick={() => setLightboxIndex(null)}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
          data-testid="attachment-lightbox"
        >
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              setLightboxIndex(null);
            }}
            aria-label="Close preview"
            className="absolute top-4 right-4 rounded-full bg-black/40 p-2 text-white hover:bg-black/60"
            data-testid="attachment-lightbox-close"
          >
            <X className="h-5 w-5" />
          </button>
          {imageAttachments.length > 1 && (
            <>
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  const total = imageAttachments.length;
                  setLightboxIndex((i) =>
                    i === null ? null : (i - 1 + total) % total,
                  );
                }}
                aria-label="Previous image"
                className="absolute left-4 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60"
                data-testid="attachment-lightbox-prev"
              >
                <ChevronLeft className="h-6 w-6" />
              </button>
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  const total = imageAttachments.length;
                  setLightboxIndex((i) =>
                    i === null ? null : (i + 1) % total,
                  );
                }}
                aria-label="Next image"
                className="absolute right-4 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60"
                data-testid="attachment-lightbox-next"
              >
                <ChevronRight className="h-6 w-6" />
              </button>
            </>
          )}
          <img
            src={imageAttachments[lightboxIndex].variantUrls.original}
            alt={imageAttachments[lightboxIndex].originalName}
            onClick={(e) => e.stopPropagation()}
            className="max-h-[90vh] max-w-[90vw] object-contain"
            data-testid="attachment-lightbox-image"
          />
        </div>
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

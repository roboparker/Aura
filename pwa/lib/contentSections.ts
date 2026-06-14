import {
  FileText,
  FolderKanban,
  MessagesSquare,
  type LucideIcon,
} from "lucide-react";

/**
 * Shared identity for the three space-content surfaces (Projects /
 * Pages / Discussions): the lucide icon and its color. Single source of
 * truth so the aggregator page headers and the sidebar nav sections
 * always render the same icon + hue per resource.
 */
export type ContentResource = "projects" | "pages" | "discussions";

export interface ContentSectionMeta {
  resource: ContentResource;
  /** Plural label / aggregator title. */
  label: string;
  /** Singular noun for the "New <singular>" affordance. */
  singular: string;
  icon: LucideIcon;
  /** Tailwind text-color classes for the icon (light + dark). */
  iconClass: string;
}

export const CONTENT_SECTIONS: ContentSectionMeta[] = [
  {
    resource: "projects",
    label: "Projects",
    singular: "project",
    icon: FolderKanban,
    iconClass: "text-violet-600 dark:text-violet-400",
  },
  {
    resource: "pages",
    label: "Pages",
    singular: "page",
    icon: FileText,
    iconClass: "text-emerald-600 dark:text-emerald-400",
  },
  {
    resource: "discussions",
    label: "Discussions",
    singular: "discussion",
    icon: MessagesSquare,
    iconClass: "text-cyan-600 dark:text-cyan-400",
  },
];

export const contentSectionFor = (
  resource: ContentResource,
): ContentSectionMeta =>
  CONTENT_SECTIONS.find((s) => s.resource === resource) as ContentSectionMeta;

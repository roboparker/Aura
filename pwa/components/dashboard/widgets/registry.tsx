import type { ComponentType } from "react";
import {
  Briefcase,
  ChartLine,
  CheckSquare,
  LayoutGrid,
  StickyNote,
  type LucideIcon,
} from "lucide-react";
import type { DashboardWidget } from "@/lib/dashboardTypes";
import AnalyticsMetricWidget from "./AnalyticsMetricWidget";
import AnalyticsStatWidget from "./AnalyticsStatWidget";
import BoardsWidget from "./BoardsWidget";
import EngagementsWidget from "./EngagementsWidget";
import MyTasksWidget from "./MyTasksWidget";
import NoteWidget from "./NoteWidget";

/**
 * What every widget component receives.
 *
 * `data` is whatever the server definition's `data()` returned, so it's typed
 * `unknown` here and narrowed by each widget. The registry can't know the shape
 * of a widget it doesn't ship with — that's the point.
 */
export interface WidgetProps {
  widget: DashboardWidget;
  data: unknown;
  isLoading: boolean;
}

export interface WidgetRenderer {
  component: ComponentType<WidgetProps>;
  icon: LucideIcon;
  /**
   * Widgets whose payload lives entirely in their config don't need a fetch.
   * Skipping it avoids a pointless round trip per note on the board.
   */
  fetchesData?: boolean;
}

/**
 * The client half of the widget contract (#759), keyed by the server's `type`.
 *
 * **Adding a widget is one entry here plus one PHP class.** Nothing else in the
 * dashboard needs to change — the page renders whatever the server sends and
 * looks the renderer up by type.
 *
 * A type missing from this map renders as a labelled placeholder rather than
 * crashing the page: a server can legitimately be ahead of a cached client, and
 * a whole dashboard shouldn't go blank because one widget is unknown.
 */
export const WIDGET_RENDERERS: Record<string, WidgetRenderer> = {
  "analytics.metric": { component: AnalyticsMetricWidget, icon: ChartLine, fetchesData: true },
  "analytics.stat": { component: AnalyticsStatWidget, icon: ChartLine, fetchesData: true },
  "engagements.table": { component: EngagementsWidget, icon: Briefcase, fetchesData: true },
  "tasks.mine": { component: MyTasksWidget, icon: CheckSquare, fetchesData: true },
  "boards.list": { component: BoardsWidget, icon: LayoutGrid, fetchesData: true },
  "notes.markdown": { component: NoteWidget, icon: StickyNote, fetchesData: false },
};

export const rendererFor = (type: string): WidgetRenderer | null =>
  WIDGET_RENDERERS[type] ?? null;

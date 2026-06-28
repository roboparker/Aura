import RemindersField from "@/components/tasks/RemindersField";
import EnablePushPrompt from "@/components/notifications/EnablePushPrompt";
import { type Reminder } from "@/components/tasks/taskHelpers";

/**
 * Reminders block for the task drawer. A thin wrapper around the shared
 * {@link RemindersField} (add-button → amount + unit → ordered list with
 * delete), plus the push opt-in nudge once at least one reminder exists.
 */
interface RemindersEditorProps {
  value: Reminder[] | null;
  /** Relative reminders anchor to this due date. */
  dueDate: string | null;
  onChange: (next: Reminder[] | null) => void | Promise<void>;
}

const RemindersEditor = ({ value, dueDate, onChange }: RemindersEditorProps) => (
  <div className="space-y-2" data-testid="reminders-editor">
    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
      Reminders
    </p>
    <RemindersField value={value} dueDate={dueDate} onChange={onChange} />
    {value && value.length > 0 && <EnablePushPrompt />}
  </div>
);

export default RemindersEditor;

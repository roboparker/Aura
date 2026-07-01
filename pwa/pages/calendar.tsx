import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import CalendarView from "@/components/calendar/CalendarView";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import { type AssigneeOption } from "@/components/tasks/AssigneesCombobox";
import { type TagOption } from "@/components/tasks/TagsCombobox";

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const CalendarPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const currentUserIri = user ? `/users/${user.id}` : null;

  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  const [allTags, setAllTags] = useState<TagOption[]>([]);
  // Bumped after a reschedule so the drawer's parent list stays coherent.
  const [refreshKey, setRefreshKey] = useState(0);

  // Deep-linkable task drawer via `?task={id}`.
  const activeTaskId =
    typeof router.query.task === "string" ? router.query.task : null;
  const openTaskDetail = useCallback(
    (taskId: string) => {
      void router.push(
        { pathname: router.pathname, query: { ...router.query, task: taskId } },
        undefined,
        { shallow: true },
      );
    },
    [router],
  );
  const handleDrawerOpenChange = useCallback(
    (open: boolean) => {
      if (open) return;
      const query = { ...router.query };
      delete query.task;
      void router.push({ pathname: router.pathname, query }, undefined, { shallow: true });
    },
    [router],
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Assignable users + tags feed the detail drawer's editors.
  useEffect(() => {
    if (!isAuthenticated) return;
    const init = {
      credentials: "include" as const,
      headers: { Accept: "application/ld+json" },
    };
    void (async () => {
      try {
        const [usersRes, tagsRes] = await Promise.all([
          fetch(`${ENTRYPOINT}/me/assignable-users`, init),
          fetch(`${ENTRYPOINT}/tags`, init),
        ]);
        if (usersRes.ok) {
          const data: Collection<AssigneeOption> = await usersRes.json();
          setAssignableUsers(data.member ?? data["hydra:member"] ?? []);
        }
        if (tagsRes.ok) {
          const data: Collection<TagOption> = await tagsRes.json();
          setAllTags(data.member ?? data["hydra:member"] ?? []);
        }
      } catch {
        // Non-fatal — the calendar still renders without drawer editors.
      }
    })();
  }, [isAuthenticated]);

  if (authLoading || !isAuthenticated) {
    return <div className="min-h-screen bg-background" />;
  }

  return (
    <>
      <Head>
        <title>Calendar - Madori</title>
      </Head>
      <main className="min-h-screen bg-background">
        <div className="w-full px-4 py-8">
          <h1 className="mb-4 text-2xl font-bold">Calendar</h1>
          {activeSpace ? (
            <CalendarView
              spaceIri={activeSpace["@id"]}
              onOpen={openTaskDetail}
              refreshSignal={refreshKey}
              assignableUsers={assignableUsers}
            />
          ) : (
            <p className="text-sm text-muted-foreground">Loading space…</p>
          )}
        </div>
      </main>

      <TaskDetailDrawer
        taskId={activeTaskId}
        open={Boolean(activeTaskId)}
        onOpenChange={handleDrawerOpenChange}
        currentUserIri={currentUserIri}
        assignableUsers={assignableUsers}
        allTags={allTags}
        onTaskChanged={() => setRefreshKey((k) => k + 1)}
        onTaskDeleted={() => setRefreshKey((k) => k + 1)}
      />
    </>
  );
};

export default CalendarPage;

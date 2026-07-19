import CommentsPanel, { type Comment } from "@/components/common/CommentsPanel";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ada = {
  "@id": "/users/1",
  id: "1",
  givenName: "Ada",
  familyName: "Lovelace",
  personalizedColor: "#0e7490",
};

const lin = {
  "@id": "/users/2",
  id: "2",
  givenName: "Lin",
  familyName: "Mei",
  personalizedColor: "#9333ea",
};

const sampleComments: Comment[] = [
  {
    "@id": "/comments/1",
    id: "1",
    body: "First pass looks great — let's ship it.",
    author: ada,
    createdAt: "2026-06-05T09:00:00Z",
    updatedAt: null,
  },
  {
    "@id": "/comments/2",
    id: "2",
    body: "Thanks! I'll clean up the edge cases first.",
    author: lin,
    createdAt: "2026-06-05T10:30:00Z",
    updatedAt: null,
  },
];

const noop = async () => {};

const CommentsPanelPage = () => (
  <ComponentDoc
    name="CommentsPanel"
    description="Flat chronological comment thread (#228) shared across task, page, and feedback parents — the surrounding page owns the create/edit/delete handlers and passes a `parentLabel`; the panel knows nothing about the parent type. Comments render in `createdAt` order with author avatars; `@mention` tokens render as inline markdown (notifications fire server-side). `currentUserIri` + `canModerate` gate the inline edit/delete affordances. Setting `canCompose={false}` (with a `composerNotice`) hides the composer for a locked thread while existing comments stay visible."
    importPath={`import CommentsPanel, { type Comment } from "@/components/common/CommentsPanel";`}
    examples={[
      {
        title: "Open thread",
        description:
          "Two comments with an active composer. `currentUserIri` matches the second author, so its edit/delete actions appear on hover.",
        code: `<CommentsPanel
  parentLabel="task"
  comments={comments}
  isLoading={false}
  currentUserIri="/users/2"
  canModerate={false}
  onCreate={handleCreate}
  onEdit={handleEdit}
  onDelete={handleDelete}
/>`,
        preview: (
          <CommentsPanel
            parentLabel="task"
            comments={sampleComments}
            isLoading={false}
            currentUserIri="/users/2"
            canModerate={false}
            onCreate={noop}
            onEdit={noop}
            onDelete={noop}
          />
        ),
      },
      {
        title: "Locked thread (no composer)",
        description:
          "Pass `canCompose={false}` on a closed thread; existing comments stay readable and `composerNotice` replaces the composer.",
        code: `<CommentsPanel
  parentLabel="thread"
  comments={comments}
  isLoading={false}
  currentUserIri="/users/2"
  canModerate={false}
  canCompose={false}
  composerNotice={
    <Alert>
      <AlertDescription>
        This thread is locked — new replies are turned away.
      </AlertDescription>
    </Alert>
  }
  onCreate={handleCreate}
  onEdit={handleEdit}
  onDelete={handleDelete}
/>`,
        preview: (
          <CommentsPanel
            parentLabel="thread"
            comments={sampleComments}
            isLoading={false}
            currentUserIri="/users/2"
            canModerate={false}
            canCompose={false}
            composerNotice={
              <p className="text-sm text-muted-foreground">
                This thread is locked — new replies are turned away.
              </p>
            }
            onCreate={noop}
            onEdit={noop}
            onDelete={noop}
          />
        ),
      },
    ]}
  />
);

export default CommentsPanelPage;

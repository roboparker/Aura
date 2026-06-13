import { useState } from "react";
import ComponentDoc from "@/components/dev/ComponentDoc";
import PermissionTree, {
  type PermissionLevel,
  type PermissionNode,
} from "@/components/common/PermissionTree";

const LEVELS: PermissionLevel[] = [
  { value: "none", label: "None" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

const NODES: PermissionNode[] = [
  {
    key: "content",
    label: "Content",
    description: "tasks, projects, pages",
    children: [
      { key: "tasks", label: "Tasks" },
      { key: "projects", label: "Projects" },
      { key: "pages", label: "Pages" },
    ],
  },
  { key: "notifications", label: "Notifications" },
  { key: "files", label: "Files" },
];

const Basic = () => {
  const [values, setValues] = useState<Record<string, string>>({
    tasks: "view",
    projects: "none",
    pages: "edit",
    notifications: "none",
    files: "view",
  });
  return (
    <div className="max-w-md rounded-md border p-3">
      <PermissionTree
        levels={LEVELS}
        nodes={NODES}
        values={values}
        masterLabel="What admins can access"
        onChange={setValues}
      />
    </div>
  );
};

const PermissionTreePage = () => (
  <ComponentDoc
    name="PermissionTree"
    description="Reusable nested permission editor. The data model is a flat `{ leafKey: levelValue }` map; the UX is three deep — a master None/Custom/All row, per-group None/Custom/All rows that expand to their leaves, and per-leaf level controls. None sets the subtree to the first (least-privileged) level, All to the last, and Custom reveals the leaves for a mixed selection. Feature-agnostic: pass your own `levels` (ordered least → most privileged) and `nodes`. First used for admin-impersonation consent; intended to back API-key scopes and other per-feature grants."
    importPath={`import PermissionTree from "@/components/common/PermissionTree";`}
    examples={[
      {
        title: "None / Custom / All over a flat map",
        description:
          "The Content group shows 'Custom' because its leaves disagree; expand it to set each. Master + group rows bulk-set their subtree.",
        code: `const [values, setValues] = useState({
  tasks: "view", projects: "none", pages: "edit",
  notifications: "none", files: "view",
});

<PermissionTree
  levels={[
    { value: "none", label: "None" },
    { value: "view", label: "View" },
    { value: "edit", label: "Edit" },
  ]}
  nodes={[
    { key: "content", label: "Content", children: [
      { key: "tasks", label: "Tasks" },
      { key: "projects", label: "Projects" },
      { key: "pages", label: "Pages" },
    ]},
    { key: "notifications", label: "Notifications" },
    { key: "files", label: "Files" },
  ]}
  values={values}
  onChange={setValues}
/>`,
        preview: <Basic />,
      },
    ]}
  />
);

export default PermissionTreePage;

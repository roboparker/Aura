// `/my-tasks` reuses the full /tasks dashboard, just routed differently.
// The shared component reads `router.pathname` to switch into a "mine"
// view: assignee filter pinned to the current user, picker hidden, new
// tasks auto-assigned to self.
import Tasks from "./tasks";

export default Tasks;

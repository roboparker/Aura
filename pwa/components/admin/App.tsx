import { HydraAdmin, ResourceGuesser } from "@api-platform/admin";

const App = () => (
  <HydraAdmin entrypoint={window.origin} title="Aura Admin">
    <ResourceGuesser name="users" />
  </HydraAdmin>
);

export default App;

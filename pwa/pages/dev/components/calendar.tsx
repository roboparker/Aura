import { useState } from "react";
import { Calendar } from "@/components/ui/calendar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SingleDate = () => {
  const [date, setDate] = useState<Date | undefined>(new Date());
  return <Calendar mode="single" selected={date} onSelect={setDate} />;
};

const CalendarPage = () => (
  <ComponentDoc
    name="Calendar"
    description="Date picker built on react-day-picker. Most consumers pair this with Popover to make a date input."
    importPath={`import { Calendar } from "@/components/ui/calendar";`}
    examples={[
      {
        title: "Single date",
        code: `const [date, setDate] = useState<Date | undefined>(new Date());

<Calendar mode="single" selected={date} onSelect={setDate} />`,
        preview: <SingleDate />,
      },
    ]}
  />
);

export default CalendarPage;

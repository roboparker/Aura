# Automating a board

A rule does something automatically when something happens on a board — reassign a task when it's completed, tag anything that goes overdue, post a comment when work moves to review.

You'll find them on the **Automations** tab of any board.

## Reading a rule

Every rule is three kinds of step:

- **When** — the thing that starts it. Every rule has exactly one.
- **Only if** — an optional check. Steps hanging below it run only when it passes.
- **Then** — what actually happens.

They're drawn as connected boxes:

```
When a task is completed
      ├──▶ Only if it's tagged "urgent" ──▶ Then notify the lead
      └──▶ Then move it to "Done"
```

Both branches come off the trigger, so completing a task always moves it to Done — but only urgent ones notify the lead.

**"Only if" is a gate, not a fork.** There's no "otherwise" path. If you want two different outcomes, add two checks off the same box.

## Building one

1. Open a board → **Automations** → **New rule**.
2. Pick what starts it.
3. Click a step in the palette to add it, then drag from the dot on one box to the dot on the next to connect them.
4. Click any box to set it up in the panel on the right.
5. **Save**, then switch it on.

New rules start **switched off**, so nothing happens while you're still building.

The canvas will tell you when a rule can't be saved yet — a step that isn't connected to anything, or a loop where two steps point at each other.

### On a phone

You can read rules on a small screen, but not edit them. Dragging boxes and drawing connections needs more room than a phone gives.

## What can start a rule

- A task is **created**
- A task is **completed**
- A task **moves to a section**
- A task's **assignee changes**
- A task's **due date passes**

Overdue is checked hourly, so a rule on it runs within the hour rather than at midnight exactly.

## Why did my task change?

Open the rule and switch to **History**. Every run is listed with each step and what it did:

- **matched** — the check passed
- **skipped** — the check didn't pass, so nothing below it ran
- **did** — the action ran
- **failed** — something went wrong; the reason is shown

Skipped steps are shown on purpose. A rule that fired and did nothing is normal, and the skipped check is the explanation.

If a run is labelled **triggered by another rule**, one rule's change set off another. That's the first thing to look at when rules seem to be fighting.

Anyone who can see the board can read the history, even if they can't edit rules. History is kept for 30 days.

## Rules that stop themselves

A few things are deliberately capped:

- A rule can't loop back on itself — that's refused when you draw it.
- Rules can set off other rules, but only a few steps deep. A chain that keeps going gets cut, and the history says so.
- A rule that keeps failing is switched off automatically, with the reason shown on the rule. Fix it and switch it back on.

These exist so a mistake costs you one confused afternoon, not a thousand notifications overnight.

## Who can do what

- **Editing rules** is limited to space admins, because a rule changes other people's tasks.
- **Reading rules and their history** is open to anyone who can see the board — usually the person asking why their task moved isn't the person who wrote the rule.

## When a rule doesn't fire

Worth checking, in order:

1. **Is it switched on?** New rules start off.
2. **Does the trigger match what actually happened?** "Moved to a section" doesn't fire when a task is created already in one.
3. **Look at History.** If runs are listed but everything is *skipped*, a check is stopping it — that's your answer.
4. **No runs at all?** The trigger never fired. A rule on one board doesn't see another board's tasks.
5. **Is there an error on the rule?** Repeated failures switch it off.

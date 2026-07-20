# Spec: Form-submit activity + web-session linking

**Status:** Draft for review
**Author:** Digital Team
**Scope:** Two related enhancements to the Ortto Gravity Forms feed.

## Summary

Today the plugin sends a Gravity Forms submission to Ortto's `v1/person/merge`
endpoint, creating/updating the person and their field values (see
`send_to_ortto()` in [`class-alpha-ortto-addon.php`](../includes/class-alpha-ortto-addon.php)).
It records **who** the person is, but not the **event** of them submitting a
form.

This spec proposes two additions:

1. **Feature 1 — Form Submit activity.** When a submission is sent to Ortto,
   also record a custom **activity** ("Form Submit") against that person, so the
   submission shows on their Ortto activity timeline and can trigger journeys.

2. **Feature 2 — Link the web-tracking session.** Ortto's tracking code
   ("Capture") assigns every browser an anonymous session. When we identify the
   person on submit, pass the tracking identifier through so Ortto marries the
   anonymous browsing history to the now-known person.

Feature 1 is well-supported by Ortto's API and low-risk. Feature 2 depends on a
tracking-code configuration mechanism that needs one verification step with
Ortto before we commit to the implementation (see
[Open questions](#open-questions)).

---

## Feature 1 — Form Submit activity

### How Ortto activities work

- A **custom activity definition** must exist in Ortto *before* events can be
  sent to it. Created under **CDP → Activities → New activity**. Renaming it in
  the UI does not change its underlying id.
- The activity id follows the pattern `act:cm:<slug>`, e.g.
  `act:cm:form-submit`. `act:` = activity, `cm:` = custom.
- Activities carry **attributes**, each typed by prefix:

  | Type      | Prefix     | Notes                                  |
  |-----------|------------|----------------------------------------|
  | Text      | `str:cm:`  | e.g. `str:cm:form-name`                |
  | Long text | `txt:cm:`  |                                        |
  | Number    | `int:cm:`  | decimals/currency ×1000                |
  | Boolean   | `bol:cm:`  |                                        |
  | Date      | `dtz:cm:`  |                                        |
  | JSON      | `obj:cm:`  |                                        |

- Limit: **50 events per activity, per contact, per 24 hours.**

### API

Activities use a **different endpoint** from the person merge we call today:

```
POST https://api.ap3api.com/v1/activities/create
```

Regional variants mirror the merge endpoint we already build in
`send_to_ortto()`: `api.au.ap3api.com` / `api.eu.ap3api.com`. We reuse the
existing `region` plugin setting and the same `X-Api-Key` header.

Payload shape:

```json
{
  "activities": [
    {
      "activity_id": "act:cm:form-submit",
      "attributes": {
        "str:cm:form-name": "Life Essentials registration",
        "int:cm:form-id": 42,
        "str:cm:entry-id": "1234"
      },
      "fields": {
        "str::email": "person@example.com"
      },
      "location": { "source_ip": "203.0.113.4" }
    }
  ],
  "merge_by": ["str::email"]
}
```

Notes:

- `fields` + `merge_by` identify the person exactly as the merge call does
  today, so the activity attaches to the same contact. Alternatively, if we have
  Ortto's `person_id`, pass `person_id` and omit `merge_by`/`fields`.
- Up to 100 activities can be sent per request (we only need one).
- `created` (ISO 8601) can backdate an event up to 90 days; not needed here —
  omit and Ortto stamps "now".

### Proposed behaviour

- After a successful `person/merge`, fire a second request to
  `v1/activities/create` for the same person, activity id `act:cm:form-submit`.
- Reuse the person identity already resolved for the merge (same `merge_by` +
  the mapped `str::email`/identity fields) so both calls target one contact.
- Suggested default attributes, all derivable from the Gravity Forms objects the
  feed already has:
  - `str:cm:form-name` — `$form['title']`
  - `int:cm:form-id` — `$form['id']`
  - `str:cm:entry-id` — `$entry['id']`
- Record the activity call's outcome alongside the existing per-feed status
  (`STATUS_META_KEY`) so the entry-detail meta box and Resend button cover it
  too.

### Configuration surface

- **Per-form feed setting** — a toggle "Send a Form Submit activity" (default
  on), plus an optional override of the activity id (default
  `act:cm:form-submit`) for forms that should log to a different activity.
- Optionally allow mapping extra activity attributes using the same
  `generic_map` UI already used for `fieldMap`, so editors can attach form
  values (e.g. course name) to the activity. Phase 2 — not required for v1.

### Failure handling

- The activity call is **secondary**: if the merge succeeds but the activity
  call fails, the person is still in Ortto. Treat the activity failure as a
  warning on the entry, not a hard feed failure, so we don't block or
  double-count the person sync. (Decision to confirm — see open questions.)

---

## Feature 2 — Link the web-tracking session

### The problem

Ortto's tracking code treats every visitor as anonymous with a generated session
id, stored in the `ap3c` cookie / `window.ap3c` object. "Once a visitor becomes
known, all of their previous anonymous browsing history is connected to their
contact record." Client-side, that link happens when `ap3c.track()` runs with an
identifier. On a server-side form submit, Ortto never sees the browser session,
so the browsing history stays detached from the person we just created.

### The supported linking mechanism

Ortto links an anonymous session to a person server-side via a **custom field
set as a tracking-code merge key**, *not* by posting a raw session id to the
merge API (there is no documented `session_id` parameter on `person/merge`).

The flow:

1. In Ortto: **Tracking code → Allowed custom field as merge key → Edit → Add
   field**, choosing (or creating) a field, e.g. `str:cm:web-session`. This
   appends the field to the merge-key priority list.
2. Client-side, the browser tells Ortto that this session belongs to that field
   value:
   ```js
   ap3c.track({ ac: [{ "fi": "str:cm:web-session", "v": "<value>" }] });
   ```
3. Server-side, our merge (and/or activity) payload sends the **same** field and
   value:
   ```json
   { "fields": { "str:cm:web-session": "<value>" }, "merge_by": ["str::email"] }
   ```
4. Ortto matches the two records on that field and merges the anonymous session
   into the identified person.

The critical constraint: **the same `<value>` must be present on both sides.**

### Proposed approach

Because both sides must agree on a value, the cleanest design is to let the
value originate in the browser and ride along with the form submission:

1. On pages hosting a form, read the tracking value the Ortto code already knows
   for this session (the `ap3c` cookie / `window.ap3c` session id), or generate
   a stable per-session id if we prefer to control it ourselves.
2. Push it to Ortto client-side via the `ap3c.track({ ac: [...] })` call above so
   the anonymous session is tagged with it.
3. Write the same value into a **hidden Gravity Forms field** on the form.
4. On submit, map that hidden field to the `str:cm:web-session` Ortto field in
   the feed's existing field mapping. The value then flows through the current
   `send_to_ortto()` path with zero new server-side code — it's just another
   mapped field.

This keeps the server side simple (reuses the field-mapping we already have) and
puts the only new moving part — a small JS snippet + hidden field — on the front
end.

### Configuration surface

- A documented setup recipe (Ortto merge-key config + the JS snippet + the
  hidden field + one mapping row). Most of this is Ortto/theme configuration
  rather than plugin code.
- Optional convenience: the plugin could enqueue the JS snippet and auto-inject
  the hidden field so editors don't hand-roll it. Decide in
  [open questions](#open-questions) whether that belongs in this plugin or the
  theme.

---

## Open questions

1. **Feature 2 mechanism — confirm with Ortto.** The docs describe the
   custom-field-as-merge-key path but are thin on reading the raw `ap3c` session
   id. Before building, confirm with Ortto support: (a) is the
   merge-key-custom-field the intended way to link server-side submits, and
   (b) can we use Ortto's own session id as the value, or should we mint our own
   linking id? This is the one item that could change the design.
2. **Where does the front-end JS live** — this plugin (enqueue script + inject
   hidden field) or the Alpha theme (alongside the existing tracking code)? The
   tracking code itself lives in the theme today.
3. **Activity failure = warning or error?** Proposed above as a non-blocking
   warning. Confirm that's the desired behaviour for the entry status / Resend
   flow.
4. **Does the "Form Submit" activity definition already exist in Ortto**, or do
   we create it (and settle its final `act:cm:` id) as part of this work?
5. **One activity per form, or one shared "Form Submit" activity** with the form
   name as an attribute? Spec assumes the latter (simpler, still filterable in
   journeys by `str:cm:form-name`).

## Out of scope

- Building the front-end tracking code itself (already installed).
- Any change to the SF ID converter / account sync webhooks.
- Blur-capture or automatic (non-form) activity capture.

## References

- [Create a custom activity event (create)](https://help.ortto.com/a-271-create-a-custom-activity-event-create)
- [Custom activities guide](https://help.ortto.com/a-233-custom-activities-guide)
- [Creating and updating people (merge)](https://help.ortto.com/a-224-creating-and-updating-people)
- [Setting a custom field as a tracking code merge key](https://help.ortto.com/a-645-setting-a-custom-field-as-a-tracking-code-merge-key)
- [Setting the tracking code merge and find strategies](https://help.ortto.com/a-646-setting-the-tracking-code-merge-and-find-strategies)
- [Tracking code](https://help.ortto.com/tracking-code)
- [Managing activities](https://help.ortto.com/a-114-managing-activities)

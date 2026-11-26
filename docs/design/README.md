# Campus TAB — screen designs

These files are **design references, not app code**. They show how the main screens should look and behave, in light and dark themes, for a made-up demo school: Sunrise Public School, Bhopal (CBSE), year 2026-27. Every name, number and amount in them is invented.

- Live, clickable versions: https://claude.ai/artifact/KVKFBoQtpUnqQ7Rn9Zg55L (pages "Light mode" and "Dark mode")
- Logo options and the chosen mark (C): https://claude.ai/artifact/JuzBmSDKYHzWcQ9XWFVsME
- Logo files for the app: `packages/ui/assets/`

## The screens

| File | Who uses it | What it shows |
| --- | --- | --- |
| `screens/light/Main.dc.html` | Principal | Dashboard: attendance, fees today, marks-entry progress, staff away, items waiting for a decision |
| `screens/light/Approvals.dc.html` | Principal | Approvals inbox; a leave request with its steps, cover periods and Approve/Reject |
| `screens/light/Markbook.dc.html` | Subject teacher | Spreadsheet-style marks entry with validation, a teacher's own column and live totals |
| `screens/light/FeeCounter.dc.html` | Clerk / fee collector | Dues with checkboxes, payment mode, receipt, and the append-only fee history |
| `screens/light/TeacherAttendance.dc.html` | Class teacher (phone) | Tap-to-change attendance for 41 students, works offline |
| `screens/light/ParentHome.dc.html` | Parent (phone) | Child switcher, today's attendance, fees due with Pay now, results, dates |
| `screens/light/Sidebar.dc.html` | Every desktop screen | Brand stack, navigation, user card, language and theme |

`screens/dark/` holds the same screens in the dark theme.

## How to read them

Each file is HTML with inline styles. The markup inside `<x-dc>` is the layout, and every colour, size and spacing value is written inline. The `<script type="text/x-dc">` block holds the demo behaviour (for example, how the markbook validates a mark). `{{name}}` placeholders are filled by that script. The `support.js` line only works on the design canvas, so open the live link above to click through the screens.

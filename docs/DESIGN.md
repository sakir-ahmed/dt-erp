# UI standards

Use the DT Restora admin kit as the reference for ERP. Use Datatech Blue (`#0B63CE`) as the light-theme primary color. Use self-hosted Poppins for Latin headings, Inter for Latin body text, and Noto Sans Bengali for Bangla. Maintain shared Tailwind tokens for light and dark themes.

- Use a compact admin layout with permission-filtered navigation and a page header with one primary action. ERP navigation has no branch switcher.
- Reuse shared components for tables, filters, form fields, dialogs, confirmations, charts, and feedback. Use Font Awesome through `AppIcon` and omit emoji.
- Provide English and Bangla translations for every user-facing string. Use shared date, number, and currency formatters.
- Show server validation beside the field, preserve input, and focus the first invalid field. Use shared dialogs for confirmations.
- Design loading, empty, error, and permission-denied states. Support keyboard navigation, visible focus, and responsive layouts in both languages and themes.

## Compact admin sizing

- Use 28 px controls with 13 px text on desktop and 36 px controls on mobile. Match control heights within a row and reserve the solid blue button for the primary action.
- Use a 48 px top bar, 220 px desktop sidebar, and 32 px navigation rows. Use 16 px desktop page padding and 12 px mobile page padding.
- Use 32 px desktop table rows with 12 px horizontal padding and horizontal dividers. Align text, identifiers, and dates to the left and numeric values to the right. Match headers to their columns. Show record cards on small screens.
- Reuse `PageHeader`, `FormField`, and `EmptyState` from `web-app/resources/js/components/ui-kit/`. The shared `ui-button` and `ui-input` styles define control sizing and interaction states.

## Create and edit forms

Use the shared `SlideOver` for create and edit workflows, following Restora's Ingredients page and MarketGrid's Products page. The panel opens from the right at 40% width on desktop and fills the viewport on phones. It has a compact header, a scrollable form body, and a pinned footer with Cancel and Save. Use two columns when space permits. Reserve centered dialogs for confirmations and short prompts.

## Save feedback

Show successful saves as a Sonner toast for three seconds. Use the top-right corner on desktop and top-center on phones. Use operation-specific messages for creation and updates. Keep validation errors beside their fields.

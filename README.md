# Egg Update Review

Replaces the "Are you sure you want to update this egg?" prompt with a review dialog. It shows a git-style diff of everything the update would change, and for each changed field or variable you decide: **Take update** or **Keep current**.

## Usage

**Admin → Eggs**: click the update button (cloud icon) on an egg that has an update. The dialog lists only what differs between your egg and the egg at its update URL:

- egg fields: name, author, description, tags, features, docker images, file denylist, startup commands, config (files/startup/logs/stop), install script/container/entrypoint, update URL
- variables, one section each, marked **(new)** or **(removed upstream)** where that applies

Everything defaults to **Take update**, which is exactly what Pelican's own update does. Switch the fields you changed yourself to **Keep current** and click **Apply update**.

## Notes

- **Removed variables:** keeping a variable that upstream removed keeps it on the egg, so servers keep their values for it. Taking the update deletes it, like Pelican does.
- **Not reviewable:** the egg icon (an upstream icon is still applied) and the variable sort order (left untouched by the importer anyway).
- **Bulk update** (selecting several eggs) still uses Pelican's normal update without review.
- **Caching:** the downloaded egg is cached for 10 minutes so the diff you see is the one applied.
- The diff compares the parsed values, so JSON formatting and line endings (`\r\n` vs `\n`) don't show up as changes.

# Local UI assets

Poppins: https://github.com/google/fonts/tree/main/ofl/poppins (SIL Open Font
License, see poppins/OFL.txt). Poppins files are served locally via poppins/fonts.css.
Lucide: https://github.com/lucide-icons/lucide (ISC and inherited MIT notices,
see lucide/LICENSE). Original SVG sources are retained in lucide/icons.

SOURCES.json records the downloaded revisions and renamed-icon aliases. The
local sprite is assembled from those SVG paths without executing vendor code.
These assets are served by the application, including on offline networks.

SweetAlert2 11.26.25: https://github.com/sweetalert2/sweetalert2/releases/tag/v11.26.25
(MIT, see sweetalert2/LICENSE). Compiled JS/CSS are hosted locally; SHA-256
digests are recorded in SOURCES.json. Shared feedback uses Lucide symbols.

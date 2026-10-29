# DevAtlas identity

The folded atlas mark represents connected systems and a clear direction. The
blue tile and cyan pointer remain recognizable on the navy sidebar and in tabs.

- `devatlas-mark.svg`: 64 x 64 app mark and favicon.
- `devatlas-logo.svg` / `devatlas-logo-dark.svg`: light and dark wordmarks.
- Matching PNG exports are provided for raster uses.
- Legacy asset filenames serve the new identity for existing links.

The application name is `APP_NAME = 'DevAtlas'` in the root `.env` file (and the
committed `env` template). Active layouts, sign-in/account pages, the dashboard,
Call Directory, Quick Links and Wiki use the `devatlas-*` SVG assets and mark as
their favicon. Both the SVG wordmarks and matching PNG exports say DevAtlas.
The `analysttoolkit-*` files are identical compatibility copies of those DevAtlas
assets; their old filenames do not contain or display the old identity.

UI typography is the locally hosted Poppins font. Interface icons are
locally hosted Lucide SVGs. Their original licenses and source revisions are in
`assets/vendor`. The mark is native SVG. No runtime CDN requests are required.

Light primary: #2563EB; navy: #0F172A; cyan: #0891B2.
Dark primary: #3B82F6; navy sidebar: #020617; cyan: #22D3EE.

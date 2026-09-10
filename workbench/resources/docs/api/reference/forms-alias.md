---
title: Listing forms
summary: A second page surfacing the forms endpoint in the same section.
nav:
  order: 2
  type: operation
  ref: GET /api/forms
---
Two pages in this section both ask to be the link to `GET /api/forms`. The sidebar
holds a destination once, so this page — second by `nav.order` — is the one whose
link is left out, with a `content.duplicate-nav-ref` warning naming it.

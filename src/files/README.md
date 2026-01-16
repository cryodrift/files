# Files

simple Files sqlite Database with cli interface  

## Routes

All routes are methods annotated with `@web` in `src/files/Web.php` and `src/files/Api.php`. They are accessible under `/files/{method}`. Available routes and parameters:

- GET /files/file — get a stored file (optionally as thumbnail)
  - params:
    - file_id (required, string): internal file id
    - format (optional, string): when set to "thumb", returns a generated/EXIF thumbnail
    - mode (optional, string): when set to "inline", sets headers for inline display; otherwise prompts download

- GET /files/search — render the search form block
  - params:
    - files_search (optional, string): search query
    - memo_id (optional, string)
    - files_menu (optional, string): current mode filter (e.g., normal|deleted|attached)

- GET /files/filelist — list files for the current user
  - params:
    - files_page (optional, int; default 0): page index
    - files_menu (optional, string): mode filter (normal|deleted|attached)

- GET /files/modefilter — dropdown for mode filtering
  - params:
    - files_menu (optional, string): selected mode (normal|deleted|attached)

- GET /files/fileviewer — iframe with inline preview of a file
  - params:
    - file_id (required, string)

- GET /files/dirs — list of known directories (as <option> items)
  - params: none

- POST /files/delete — move files to trash
  - params:
    - id (required, JSON array): selection payload; each entry must contain an "id" like "row_<uid>"

- POST /files/undelete — restore files from trash
  - params:
    - id (required, JSON array): selection payload; each entry must contain an "id" like "row_<uid>"

- POST /files/move — move files to a folder
  - params:
    - id (required, JSON array): selection payload; each entry must contain an "id" like "row_<uid>"
    - value (required, JSON object): destination; include "move_path" or "move_dir" with the target directory

Notes:
- GET parameters are passed via query string; POST parameters are passed in the request body. The UI templates in `src/files/ui/*` generate these requests.

## CLI

- Show available commands:
  php index.php /files/cli -help

- Example: list files in a path
  php index.php /files/cli ls "pub/uploads"

- Example: remove a file
  php index.php /files/cli rm "pub/uploads/old.txt"

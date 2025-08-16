# Photobook Editor

A modern web-based photobook editor built with **Laravel** (backend) and **React + TypeScript** (frontend).  
It enables users to design, review, and customize photobooks with advanced layout, image, and template controls.

---

## Features

- **Album Management:**  
  Browse, select, and switch between previously built albums (folders) with cached pages.

- **Page Editor:**  
  - Drag-and-drop image positioning (object-position, momentum-less drag).
  - Per-image zoom slider (background-size simulation).
  - Keyboard nudges (arrow keys, Shift for larger steps).
  - Filmstrip for quick photo selection and drag-reordering.
  - Replace Drawer: swap images from candidate suggestions.
  - Template Picker: change page layout and remap images.
  - Snap-to-face: auto-center images on detected faces (ML features).
  - Undo/Redo (in-memory, last 20 actions).
  - UI state persistence (localStorage).

- **Backend APIs:**  
  - `GET /photobook/albums` — List available albums (folders with pages.json).
  - `GET /photobook/pages?folder=...` — Get detailed pages.json for an album.
  - `POST /photobook/override` — Override page template.
  - `POST /photobook/save-page` — Save page edits (objectPosition, scale, etc).
  - `GET /photobook/candidates?folder=&page=` — List candidate images for replacement.
  - `GET /photobook/asset/{hash}/{path}` — Serve cached images for web display.

- **Styling:**  
  - Tailwind CSS for all UI components.
  - No inline styles; all dynamic positioning via CSS classes or variables.

---

## Getting Started

### Prerequisites

- PHP >= 8.1
- Composer
- Node.js >= 18
- npm
- SQLite or MySQL (for Laravel, if needed)

### Installation

1. **Clone the repository:**
   ```sh
   git clone https://github.com/your-org/photobook.git
   cd photobook
   ```

2. **Install backend dependencies:**
   ```sh
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

3. **Install frontend dependencies:**
   ```sh
   npm install
   ```

4. **Build or start the frontend:**
   - For development (hot reload):
     ```sh
     npm run dev
     ```
   - For production:
     ```sh
     npm run build
     ```

5. **Start Laravel server:**
   ```sh
   php artisan serve
   ```

6. **Access the editor:**
   - Open [http://localhost:8000/photobook/editor](http://localhost:8000/photobook/editor) in your browser.

---

## Folder Structure

```
photobook/
├── app/                  # Laravel backend (controllers, services, jobs, DTOs)
├── resources/
│   ├── photobook-editor/ # React + TypeScript frontend
│   │   ├── src/
│   │   │   ├── api/      # API client and types
│   │   │   ├── components/ # Editor UI components
│   │   │   ├── hooks/    # Custom React hooks
│   │   │   ├── state/    # Zustand state management
│   │   ├── main.tsx      # React entry point
│   │   ├── index.css     # Tailwind CSS entry
│   ├── views/            # Blade templates
│   ├── css/              # Global CSS
│   ├── js/               # Global JS
├── storage/app/pdf-exports/_cache/ # Album caches (pages.json, images, logs)
├── routes/               # Laravel routes (web.php, console.php)
├── public/               # Public assets
├── README.md
```

---

## Usage

- **Build an album:**  
  Use the backend job or CLI to generate a photobook. This creates a folder under `storage/app/pdf-exports/_cache/<hash>/` with a `pages.json` and images.

- **Open the editor:**  
  Visit `/photobook/editor`, select an album from the dropdown, and start editing.

- **Edit pages:**  
  - Drag images, zoom, reorder, replace, and change templates.
  - Save changes to persist overrides and edits.

- **Switch albums:**  
  Use the dropdown to load other cached albums.

---

## Development Notes

- **Frontend:**  
  - Uses Vite for fast development and hot module reload.
  - React Query for API data, Zustand for selection state.
  - All styling via Tailwind CSS.

- **Backend:**  
  - Laravel controllers expose RESTful endpoints for all editor actions.
  - Asset serving via `/photobook/asset/{hash}/{path}` for secure image access.

- **Image URLs:**  
  - Always use `web` or `webSrc` fields for images in the frontend (never system paths).

---

## Roadmap

- [x] Replace Drawer with candidate filters and preserve crop toggle.
- [ ] Template Picker with slot remapping and previews.
- [ ] Snap-to-face (ML features endpoint and UI).
- [ ] Undo/Redo and autosave.
- [ ] Page navigation strip and snapping guides.
- [ ] Album rebuild, export, and review tools.

---

## Contributing

Pull requests and issues are welcome!  
See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Credits

Built with [Laravel](https://laravel.com), [React](https://react.dev), [Tailwind CSS](https://tailwindcss.com)
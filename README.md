# YouGo

Laravel + React/Inertia SaaS pentru saloane, cu MySQL local si assistant public server-side prin Gemini.

## Local setup

1. Creeaza baza de date MySQL:
   ```sql
   CREATE DATABASE yougo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Configureaza `.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=yougo
   DB_USERNAME=root
   DB_PASSWORD=
   GEMINI_API_KEY=
   ```
3. Ruleaza:
   ```bash
   composer install
   npm install
   php artisan key:generate
   php artisan migrate
   npm run dev
   php artisan serve
   ```

Pentru build de productie:

```bash
npm run build
```

# AI Service Image Import

## Purpose

AI Service Image Import helps business owners set up their own service list faster. It reads a photo or screenshot of a business service menu and prepares service rows for review before saving them in Dashboard.

This feature imports the business owner's services. It is separate from YouGo plans, commercial service catalog entries, and plan entitlements.

## Where It Appears

Dashboard -> Services.

The button appears after Add service:

- Romanian: Importa cu AI
- English: Import with AI

## How It Works

1. Open Dashboard -> Services.
2. Click Importa cu AI / Import with AI.
3. Upload a JPG, PNG, or WebP image of a service list.
4. Click Analyze image.
5. Review the detected services.
6. Uncheck anything that should not be saved.
7. Edit names, duration, price, description, or notes if needed.
8. Click Insert selected services.

## Fields Extracted

The AI tries to extract:

- service category or visible section heading
- service name
- duration in minutes
- price as a number
- short description
- notes

If duration or price is unclear, the field may be left blank for manual review.

When a category is detected, YouGo saves it on the imported service and adds new category names to the business service category list.

## Review Before Insert

YouGo never creates services silently from the image. The user must review the extracted rows and approve the selected services before they are inserted.

## Duplicate Handling

If a service with the same normalized name already exists in the business service list, YouGo skips it during insertion. The review screen can also mark obvious duplicates before saving.

## Limitations

The AI can make mistakes when the image is blurry, cropped, handwritten, low contrast, or contains complex layouts. Users should review every imported row before inserting.

## Image Quality Tips

Use a clear photo with the full service list visible. Avoid glare, heavy shadows, angled shots, and screenshots where text is too small.

## Privacy And Safety

Images are used only to detect candidate services for this import flow. YouGo does not create services until the user confirms the reviewed list.

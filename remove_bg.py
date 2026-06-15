from PIL import Image
import os

# Load the logo image
img_path = r'c:\xampp\htdocs\csuweb\image\Csu.png'
img = Image.open(img_path)

# Convert to RGBA if not already
if img.mode != 'RGBA':
    img = img.convert('RGBA')

# Get image data
data = img.getdata()
new_data = []

# Replace white pixels with transparent
# White is RGB(255, 255, 255) or close to it
for item in data:
    # Check if pixel is white (or very close to white)
    if item[0] > 240 and item[1] > 240 and item[2] > 240:
        # Replace with transparent
        new_data.append((255, 255, 255, 0))
    else:
        new_data.append(item)

# Update image data
img.putdata(new_data)

# Save the processed image
output_path = r'c:\xampp\htdocs\csuweb\image\Csu.png'
img.save(output_path, 'PNG')

print(f"White background removed successfully!")
print(f"Image saved to: {output_path}")

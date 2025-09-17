document.addEventListener("DOMContentLoaded", () => {
    fetchInventory();
});

const inventoryBody = document.getElementById("inventoryBody");
const inventoryModal = document.getElementById("inventoryModal");
const inventoryForm = document.getElementById("inventoryForm");
const itemIdField = document.getElementById("itemId");
const itemNameField = document.getElementById("itemName");
const itemCategoryField = document.getElementById("itemCategory");
const itemQuantityField = document.getElementById("itemQuantity");
const itemUnitField = document.getElementById("itemUnit");
const modalTitle = document.getElementById("inventoryModalTitle");
const categoryModal = document.getElementById("categoryModal");
const categoryForm = document.getElementById("categoryForm");
const categoryIdField = document.getElementById("categoryId");
const categoryNameField = document.getElementById("categoryName");
const categoryDescriptionField = document.getElementById("categoryDescription");

let categories = [];

// Load inventory
async function fetchInventory() {
    try {
        const res = await fetch("php/inventory.php?action=list");
        const data = await res.json();

        categories = data.categories || [];
        const items = data.items || [];

        // Populate category dropdown
        itemCategoryField.innerHTML = "";
        categories.forEach(cat => {
            let opt = document.createElement("option");
            opt.value = cat.id;
            opt.textContent = cat.name;
            itemCategoryField.appendChild(opt);
        });
        // Populate category table
        const categoryBody = document.getElementById("categoryBody");
        if (categoryBody) {
            categoryBody.innerHTML = "";
            categories.forEach(cat => {
                const row = document.createElement("tr");
                row.innerHTML = `
            <td class="px-6 py-3">${cat.name}</td>
            <td class="px-6 py-3">${cat.description || ""}</td>
            <td class="px-6 py-3">
                <button class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition"
                    onclick="editCategory(${cat.id})">
                    <i class="fa-solid fa-pen"></i> Edit
                </button>
            </td>
        `;
                categoryBody.appendChild(row);
            });
        }


        // Populate table
        inventoryBody.innerHTML = "";
        items.forEach(item => {
            const row = document.createElement("tr");
            row.innerHTML = `
                <td class="px-6 py-3">${item.name}</td>
                <td class="px-6 py-3">${item.category_name}</td>
                <td class="px-6 py-3">${item.quantity}</td>
                <td class="px-6 py-3">${item.unit}</td>
                <td class="px-6 py-3">
                    <button class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition"
                        onclick="editItem(${item.id})">
                        <i class="fa-solid fa-pen"></i> Edit
                    </button>
                </td>
            `;
            inventoryBody.appendChild(row);
        });
    } catch (err) {
        console.error("Error fetching inventory:", err);
    }
}

// Open modal for add
function openInventoryModal() {
    modalTitle.textContent = "Add Item";
    inventoryForm.reset();
    itemIdField.value = "";
    inventoryModal.classList.remove("hidden");
}

// Edit existing item
async function editItem(id) {
    try {
        const res = await fetch(`php/inventory.php?action=get&id=${id}`);
        const item = await res.json();

        modalTitle.textContent = "Edit Item";
        itemIdField.value = item.id;
        itemNameField.value = item.name;
        itemCategoryField.value = item.category_id;
        itemQuantityField.value = item.quantity;
        itemUnitField.value = item.unit;

        inventoryModal.classList.remove("hidden");
    } catch (err) {
        console.error("Error loading item:", err);
    }
}

// Close modal
function closeInventoryModal() {
    inventoryModal.classList.add("hidden");
}

// Save item
inventoryForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const payload = {
        id: itemIdField.value,
        name: itemNameField.value,
        category_id: itemCategoryField.value,
        quantity: itemQuantityField.value,
        unit: itemUnitField.value
    };

    try {
        const res = await fetch("php/inventory.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });
        const result = await res.json();

        if (result.success) {
            closeInventoryModal();
            fetchInventory();
        } else {
            alert("Error: " + result.message);
        }
    } catch (err) {
        console.error("Error saving item:", err);
    }
});

// Open category modal
function openCategoryModal() {
    document.getElementById("categoryModalTitle").textContent = "Add Category";
    categoryForm.reset();
    categoryIdField.value = "";
    categoryModal.classList.remove("hidden");
}

function closeCategoryModal() {
    categoryModal.classList.add("hidden");
}
async function editCategory(id) {
    const cat = categories.find(c => c.id == id);
    if (!cat) return;

    document.getElementById("categoryModalTitle").textContent = "Edit Category";
    categoryIdField.value = cat.id;
    categoryNameField.value = cat.name;
    categoryDescriptionField.value = cat.description || "";

    categoryModal.classList.remove("hidden");
}


// Save category
categoryForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        id: categoryIdField.value,
        name: categoryNameField.value,
        description: categoryDescriptionField.value
    };

    try {
        const res = await fetch("php/inventory.php?action=saveCategory", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });
        const result = await res.json();

        if (result.success) {
            closeCategoryModal();
            fetchInventory(); // refresh dropdown + table
        } else {
            alert("Error: " + result.message);
        }
    } catch (err) {
        console.error("Error saving category:", err);
    }
});

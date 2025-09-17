document.addEventListener("DOMContentLoaded", loadServices);

const serviceModal = document.getElementById("serviceModal");
const serviceForm = document.getElementById("serviceForm");
const servicesBody = document.getElementById("servicesBody");

function openServiceModal(service = null) {
    serviceModal.classList.remove("hidden");
    if (service) {
        document.getElementById("serviceModalTitle").textContent = "Edit Service";
        document.getElementById("serviceId").value = service.id;
        document.getElementById("serviceName").value = service.name;
        document.getElementById("servicePrice").value = service.price;
    } else {
        serviceForm.reset();
        document.getElementById("serviceModalTitle").textContent = "Add Service";
    }
}

function closeServiceModal() {
    serviceModal.classList.add("hidden");
}

// Load services from backend
async function loadServices() {
    try {
        let res = await fetch("php/services.php?action=read");
        let data = await res.json();
        servicesBody.innerHTML = "";
        data.forEach(service => {
            servicesBody.innerHTML += `
                <tr>
                    <td class="px-6 py-3">${service.name}</td>
                    <td class="px-6 py-3">₱${parseFloat(service.price).toFixed(2)}</td>
                    <td class="px-6 py-3">
                        <button onclick='openServiceModal(${JSON.stringify(service)})'
                            class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition">Edit</button>
                    </td>
                </tr>`;
        });
    } catch (err) {
        console.error("Error loading services:", err);
    }
}

// Handle Add/Edit form submit
serviceForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const serviceData = {
        id: document.getElementById("serviceId").value,
        name: document.getElementById("serviceName").value,
        price: document.getElementById("servicePrice").value
    };

    let action = serviceData.id ? "update" : "create";

    try {
        let res = await fetch("php/services.php?action=" + action, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(serviceData)
        });
        let msg = await res.text();
        alert(msg);
        closeServiceModal();
        loadServices();
    } catch (err) {
        console.error("Error saving service:", err);
    }
});

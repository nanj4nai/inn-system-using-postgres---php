const API_URL = "php/rooms.php"; // adjust if needed

function showNotification(message, type = "success") {
    const notif = document.getElementById("notification");
    notif.className = `p-4 rounded mb-4 ${type === "success"
        ? "bg-green-100 text-green-700 border border-green-400"
        : "bg-red-100 text-red-700 border border-red-400"
        }`;
    notif.innerText = message;
    notif.classList.remove("hidden");

    setTimeout(() => {
        notif.classList.add("hidden");
    }, 3000);
}

// Sidebar toggle
document.getElementById("menu-button").addEventListener("click", () => {
    document.getElementById("sidebar").classList.toggle("active");
});

// Open Add Modal
document.getElementById("addRoomBtn").addEventListener("click", () => {
    openAddModal();
});

function openAddModal() {
    document.getElementById("modalTitle").innerText = "Add Room";
    document.getElementById("roomForm").reset();
    document.getElementById("roomId").value = "";
    document.getElementById("roomModal").classList.remove("hidden");
}

function closeModal() {
    document.getElementById("roomModal").classList.add("hidden");
}

// Fetch all rooms
async function loadRooms() {
    const res = await fetch(API_URL);
    if (res.status === 403) {
        window.location.href = "login.html";
        return;
    }
    const rooms = await res.json();
    const tbody = document.getElementById("roomsTable");
    tbody.innerHTML = "";

    rooms.forEach(room => {
        const basePrice = parseFloat(room.base_price) || 0;
        const extraPrice = parseFloat(room.extra_hour_price) || 0;
        const minHours = parseInt(room.min_hours) || 0;

        tbody.innerHTML += `
      <tr class="border-b">
        <td class="px-4 py-2">${room.room_number}</td>
        <td class="px-4 py-2">${room.room_type}</td>
        <td class="px-4 py-2">₱${basePrice.toFixed(2)} for ${minHours} hrs 
            <br><span class="text-sm text-gray-600">
                Every additional hour: +₱${extraPrice.toFixed(2)}
            </span>
        </td>
        <td class="px-4 py-2">${room.capacity} Guests</td>
        <td class="px-4 py-2">${room.status}</td>
        <td class="px-4 py-2">
          <button onclick="editRoom(${room.id})" 
                  class="bg-yellow-500 text-white px-2 py-1 rounded">Edit</button>
        </td>
      </tr>
    `;
    });
}

function editRoom(id) {
    fetch(`${API_URL}?id=${id}`)
        .then(res => {
            if (res.status === 403) {
                window.location.href = "login.html";
                return;
            }
            return res.json();
        })
        .then(room => {
            if (!room) return;
            document.getElementById("modalTitle").innerText = "Edit Room";
            document.getElementById("roomId").value = room.id;
            document.getElementById("roomNumber").value = room.room_number;
            document.getElementById("roomType").value = room.room_type;
            document.getElementById("roomMinHours").value = room.min_hours;
            document.getElementById("roomBasePrice").value = room.base_price;
            document.getElementById("roomExtraPrice").value = room.extra_hour_price;
            document.getElementById("roomCapacity").value = room.capacity;
            document.getElementById("roomStatus").value = room.status;
            document.getElementById("roomModal").classList.remove("hidden");
        });
}

document.getElementById("roomForm").onsubmit = async (e) => {
    e.preventDefault();
    const id = document.getElementById("roomId").value;
    const payload = {
        room_number: document.getElementById("roomNumber").value,
        room_type: document.getElementById("roomType").value,
        status: document.getElementById("roomStatus").value,
        min_hours: parseInt(document.getElementById("roomMinHours").value),
        base_price: parseFloat(document.getElementById("roomBasePrice").value),
        extra_hour_price: parseFloat(document.getElementById("roomExtraPrice").value),
        capacity: parseInt(document.getElementById("roomCapacity").value)
    };

    let method = id ? "PUT" : "POST";
    let url = id ? `${API_URL}?id=${id}` : API_URL;

    const res = await fetch(url, {
        method,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });

    if (res.status === 403) {
        window.location.href = "login.html";
        return;
    }

    closeModal();
    loadRooms();
};

// Initial load
loadRooms();

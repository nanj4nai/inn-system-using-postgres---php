const tbody = document.getElementById("guestsBody");
const modal = document.getElementById("guestModal");
const form  = document.getElementById("guestForm");
const guestIdField = document.getElementById("guestId");

function openGuestModal(id=null) {
  modal.classList.remove("hidden");
  if (id) {
    document.getElementById("guestModalTitle").textContent = "Edit Guest";
    fetch(`php/guests.php?id=${id}`)
      .then(res => res.json())
      .then(data => {
        guestIdField.value = data.id;
        document.getElementById("fullName").value = data.full_name;
        document.getElementById("phone").value = data.phone || "";
        document.getElementById("email").value = data.email || "";
      })
      .catch(err => alert("Failed to load guest: " + err));
  } else {
    document.getElementById("guestModalTitle").textContent = "Add Guest";
    form.reset();
    guestIdField.value = "";
  }
}
function closeGuestModal() {
  modal.classList.add("hidden");
}

// Load guests
async function loadGuests() {
  try {
    const res = await fetch("php/guests.php");
    if (!res.ok) throw new Error("Failed to load guests");
    const guests = await res.json();
    tbody.innerHTML = "";
    guests.forEach(g => {
      tbody.innerHTML += `
        <tr class="border-t">
          <td class="px-4 py-2">${g.full_name}</td>
          <td class="px-4 py-2">${g.phone || "-"}</td>
          <td class="px-4 py-2">${g.email || "-"}</td>
          <td class="px-4 py-2 space-x-2">
            <button onclick="openGuestModal(${g.id})" class="text-yellow-600 hover:underline"><i class="fa-solid fa-edit"></i> Edit</button>
            <button onclick="deleteGuest(${g.id})" class="text-red-600 hover:underline"><i class="fa-solid fa-trash"></i> Delete</button>
          </td>
        </tr>
      `;
    });
  } catch (err) {
    alert(err.message);
  }
}

// Save guest
form.addEventListener("submit", async (e) => {
  e.preventDefault();
  const id = guestIdField.value;
  const payload = {
    full_name: document.getElementById("fullName").value,
    phone: document.getElementById("phone").value,
    email: document.getElementById("email").value
  };

  try {
    let res;
    if (id) {
      res = await fetch(`php/guests.php?id=${id}`, {
        method: "PUT",
        body: JSON.stringify(payload)
      });
    } else {
      res = await fetch("php/guests.php", {
        method: "POST",
        body: JSON.stringify(payload)
      });
    }

    if (!res.ok) throw new Error("Save failed");
    closeGuestModal();
    loadGuests();
  } catch (err) {
    alert(err.message);
  }
});

// Delete guest
async function deleteGuest(id) {
  if (!confirm("Are you sure you want to delete this guest?")) return;
  try {
    const res = await fetch(`php/guests.php?id=${id}`, { method: "DELETE" });
    if (!res.ok) throw new Error("Delete failed");
    loadGuests();
  } catch (err) {
    alert(err.message);
  }
}

document.addEventListener("DOMContentLoaded", loadGuests);

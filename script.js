const fileInput = document.getElementById("selectedFile");
const selectedText = document.getElementById("selected");

fileInput.addEventListener("change", function() {
    if (fileInput.files.length > 0) {
        selectedText.textContent = "SELECTED: " + fileInput.files[0].name;
    } else {
        selectedText.textContent = "No file selected"; 
    }
});
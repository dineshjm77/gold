<!-- Right Sidebar -->
<div class="right-bar">
    <div data-simplebar class="h-100">
        <div class="rightbar-title px-3 py-4">
            <a href="javascript:void(0);" class="right-bar-toggle float-end">
                <i class="mdi mdi-close noti-icon"></i>
            </a>
            <h5 class="m-0">Settings</h5>
        </div>
        
        <hr class="my-0">
        
        <div class="p-3">
            <h6>Theme Settings</h6>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="theme" id="light-mode" checked>
                <label class="form-check-label" for="light-mode">Light Mode</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="theme" id="dark-mode">
                <label class="form-check-label" for="dark-mode">Dark Mode</label>
            </div>
        </div>
    </div>
</div>

<style>
.right-bar {
    position: fixed;
    top: 0;
    right: -300px;
    width: 300px;
    height: 100%;
    background: #fff;
    box-shadow: -2px 0 10px rgba(0,0,0,0.1);
    z-index: 1000;
    transition: right 0.3s ease;
}
.right-bar.open {
    right: 0;
}
.rightbar-title {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.right-bar-toggle {
    color: white;
    font-size: 20px;
    cursor: pointer;
}
.right-bar-toggle:hover {
    color: #f0f0f0;
}
</style>
<div id="preloader">
    <div id="status">
        <div class="spinner-chase">
            <div class="chase-dot"></div>
            <div class="chase-dot"></div>
            <div class="chase-dot"></div>
            <div class="chase-dot"></div>
            <div class="chase-dot"></div>
            <div class="chase-dot"></div>
        </div>
    </div>
</div>
<style>
#preloader {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #fff;
    z-index: 9999;
}
#status {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
}
.spinner-chase {
    width: 40px;
    height: 40px;
    position: relative;
    animation: spinner-chase 2.5s infinite linear both;
}
.chase-dot {
    width: 100%;
    height: 100%;
    position: absolute;
    left: 0;
    top: 0;
    animation: chase-dot 2.0s infinite ease-in-out both;
}
.chase-dot:before {
    content: '';
    display: block;
    width: 25%;
    height: 25%;
    background-color: #667eea;
    border-radius: 100%;
    animation: chase-dot-before 2.0s infinite ease-in-out both;
}
@keyframes spinner-chase {
    100% { transform: rotate(360deg); }
}
@keyframes chase-dot {
    80%, 100% { transform: rotate(360deg); }
}
@keyframes chase-dot-before {
    50% { transform: scale(0.4); }
    100%, 0% { transform: scale(1.0); }
}
</style>
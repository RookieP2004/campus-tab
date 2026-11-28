//! Campus TAB for Windows and macOS.
//!
//! The window shows the same React build as the browser, built with
//! VITE_APP_SHELL=desktop and the API address set at build time. The
//! sign-in token lives only in the web view's memory: closing the window
//! ends the process, which is the sign-out. Nothing about the sign-in is
//! written to disk by this shell.
//!
//! - One running copy: opening a campustab:// link while the app runs
//!   hands the link to that copy (single-instance + deep-link).
//! - Printing uses the system print dialog (window.print in the web view).
//! - Auto-update only in builds made with the `updater` feature.

use tauri::Manager;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default()
        // Must be first: a second launch (e.g. from a link) focuses the first window.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.unminimize();
                let _ = window.set_focus();
            }
        }))
        .plugin(tauri_plugin_deep_link::init());

    #[cfg(feature = "updater")]
    let builder = builder.plugin(tauri_plugin_updater::Builder::new().build());

    builder
        .setup(|app| {
            // Windows and Linux register the campustab:// scheme at run time
            // (macOS gets it from the bundle's Info.plist).
            #[cfg(any(windows, target_os = "linux"))]
            {
                use tauri_plugin_deep_link::DeepLinkExt;
                let _ = app.deep_link().register_all();
            }

            #[cfg(feature = "updater")]
            {
                let handle = app.handle().clone();
                tauri::async_runtime::spawn(async move {
                    use tauri_plugin_updater::UpdaterExt;
                    // A signed update is downloaded and installed; it takes effect on the next start.
                    if let Ok(updater) = handle.updater() {
                        if let Ok(Some(update)) = updater.check().await {
                            let _ = update.download_and_install(|_, _| {}, || {}).await;
                        }
                    }
                });
            }

            let _ = app;
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("Campus TAB could not start");
}

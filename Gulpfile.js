const pinfo = require("./package.json");

const gulp = require("gulp");
const replace = require("gulp-replace");

gulp.task("readme-version", function () {
    return gulp
        .src("src/readme.md")
        .pipe(replace("$PLUGINVERSION$", pinfo.version))
        .pipe(replace("$PLUGINATLEAST$", pinfo.config.eduadmin.requiresAtLeast))
        .pipe(replace("$PLUGINTESTEDTO$", pinfo.config.eduadmin.testedUpTo))
        .pipe(
            replace(
                "$PLUGINREQUIREDPHP$",
                pinfo.config.eduadmin.minimumPhpVersion
            )
        )
        .pipe(gulp.dest("./"));
});

gulp.task("eduadmin-version", function () {
    return gulp
        .src("src/eduadmin-dibs-easy-integration.php")
        .pipe(replace("$PLUGINVERSION$", pinfo.version))
        .pipe(replace("$PLUGINATLEAST$", pinfo.config.eduadmin.requiresAtLeast))
        .pipe(replace("$PLUGINTESTEDTO$", pinfo.config.eduadmin.testedUpTo))
        .pipe(gulp.dest("./"));
});

gulp.task("default", function () {
    gulp.watch("src/eduadmin-dibs-easy-integration.php", gulp.series("eduadmin-version"));
    gulp.watch("src/readme.md", gulp.series("readme-version"));
})

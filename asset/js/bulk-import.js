'use strict';

(function ($) {
    $(document).ready(function() {

        const basePath = window.location.pathname.replace(/\/admin\/.*/, '');
        const uploadUrl = basePath + '/admin/bulk/upload';

        // May avoid crash on big import and small user computer.
        const maxSizeThumbnail = 15000000;
        const maxTotalSizeThumbnails = 500000000;
        const maxCountThumbnails = 200;

        let bulkUpload = $($('#media-template-bulk_upload').data('template')).find('.media-bulk-upload');
        const allowedMediaTypes = bulkUpload.data('allowed-media-types') ? bulkUpload.data('allowed-media-types').split(',') : [];
        const allowedExtensions = bulkUpload.data('allowed-extensions') ? bulkUpload.data('allowed-extensions').split(',') : [];
        const allowEmptyFiles = !!bulkUpload.data('allowEmptyFiles');

        // Adapted from https://developer.mozilla.org/fr/docs/Web/HTML/Element/Input/file (licence cc0/public domain).
        // Adapted from https://developer.mozilla.org/fr/docs/Web/API/File/Using_files_from_web_applications
        // @see https://github.com/flowjs/flow.js
        $('#media-selector').on('click', 'button[type=button][data-media-type=bulk_upload]', function (e) {
            // Get the last media in media list, that is the new one.
            const mediaField = $('#media-list').find('.media-bulk-upload').last()[0];
            const mainIndex = mediaField.getAttribute('data-main-index');
            const submitReady = mediaField.parentNode.getElementsByClassName('submit-ready')[0];
            const inputFilesData = mediaField.parentNode.getElementsByClassName('filesdata')[0];
            const fullProgress = mediaField.parentNode.getElementsByClassName('media-files-input-full-progress')[0];
            const fullProgressCurrent = fullProgress.getElementsByClassName('progress-current')[0];
            const fullProgressTotal = fullProgress.getElementsByClassName('progress-total')[0];
            const fullProgressWait = fullProgress.getElementsByClassName('progress-wait')[0];
            const preview = mediaField.parentNode.getElementsByClassName('media-files-input-preview')[0];
            const listUploaded = preview.getElementsByTagName('ol')[0];
            const buttonBrowseFiles = mediaField.getElementsByClassName('button-browse-files')[0];
            const buttonBrowseDirectory = mediaField.getElementsByClassName('button-browse-directory')[0];
            const buttonPause = mediaField.getElementsByClassName('button-pause')[0];
            const divDrop = mediaField.getElementsByClassName('bulk-drop')[0];
            const bulkUploadActions = mediaField.parentNode.getElementsByClassName('bulk-upload-actions')[0];
            const buttonSort = bulkUploadActions.getElementsByClassName('button-sort');

            const flow = new Flow({
                target: uploadUrl,
                chunkSize: 1000000,
                permanentErrors: [403, 404, 412, 415, 500, 501],
                headers: {
                    'X-Csrf': $('body.items form.resource-form input[type=hidden][name=csrf]').val(),
                },
                // Like default one, but prepend the main index to allow uploading same files multiple times in bulk-uploads.
                generateUniqueIdentifier: (file) => {
                    var relativePath = file.relativePath || file.webkitRelativePath || file.fileName || file.name;
                    return mainIndex + '_' + file.size + '-' + relativePath.replace(/[^0-9a-zA-Z_-]/img, '');
                },
            });
            if (!flow.support) {
                return;
            }

            bulkUploadActions.style.display = 'none';

            const accept = (allowedMediaTypes + ',' + allowedExtensions).replace(/^,+|,+$/g, '');

            flow.assignBrowse(buttonBrowseFiles, false, false, accept.length ? {'accept': accept} : {});
            // Fix Apple Safari.
            flow.supportDirectory
                ? flow.assignBrowse(buttonBrowseDirectory, true, false, accept.length ? {'accept': accept} : {})
                : buttonBrowseDirectory.style.display = 'none';
            flow.assignDrop(divDrop);

            flow.on('fileAdded', (file, event) => {
                // Disable resource form submission until full upload.
                // Just use "required", that is managed by the browser.
                submitReady.setAttribute('required', 'required');
                fullProgress.classList.remove('empty');
                fullProgressCurrent.textContent = $(listUploaded).find('li[data-is-valid=1][data-is-uploaded=1]').length
                fullProgressTotal.textContent = $(listUploaded).find('li[data-is-valid=1]').length + 1;
                fullProgressWait.style.display = 'block';
                bulkUploadActions.style.display = 'none';

                const countThumbnails = listUploaded.getElementsByClassName('original-thumbnail').length;
                var totalSizeThumbnails = 0;
                for (const item of listUploaded.getElementsByClassName('original-thumbnail')) {
                    totalSizeThumbnails += parseInt(item.getAttribute('data-size'));
                }

                const fileFile = file.file;
                const listItem = document.createElement('li');
                const listItemIsValid = validateFile(fileFile);
                listItem.id = file.uniqueIdentifier;
                listItem.setAttribute('data-filename', file.name);
                listItem.setAttribute('data-filepath', file.relativePath);
                listItem.setAttribute('data-is-valid', listItemIsValid ? '1' : '0');
                listItem.setAttribute('data-is-uploaded', '0');
                const div = document.createElement('div');
                div.classList.add('media-info');
                if (listItemIsValid) {
                    // Display thumbnail and data.
                    const divImage = document.createElement('div');
                    divImage.classList.add('resource-thumbnail');
                    const image = document.createElement('img');
                    const mainType = fileFile.type.split('/')[0];
                    const subType = fileFile.type.split('/')[1];
                    const newThumbnail = mainType === 'image'
                        && ['avif', 'apng', 'bmp', 'gif', 'ico', 'jpeg', 'png', 'svg', 'webp'].includes(subType)
                        && file.size <= maxSizeThumbnail
                        && countThumbnails < maxCountThumbnails
                        && totalSizeThumbnails <= maxTotalSizeThumbnails;
                    if (newThumbnail) {
                        totalSizeThumbnails += file.size;
                        image.src = URL.createObjectURL(fileFile);
                        image.classList.add('original-thumbnail');
                        image.setAttribute('data-size', file.size);
                    } else {
                        image.src = defaultThumbnailUrl(fileFile);
                    }
                    image.onload = function() {
                        URL.revokeObjectURL(this.src);
                    };
                    divImage.appendChild(image);
                    div.appendChild(divImage);
                    const span = document.createElement('span');
                    span.classList.add('resource-name');
                    span.textContent = `${file.name} (${humanFileSize(file.size)})`;
                    div.appendChild(span);
                    listItem.appendChild(div);
                    // Create a throbber and attach it to the file.
                    file.ctrl = createThrobber(image);
                } else {
                    const pError = document.createElement('p');
                    pError.textContent = file.name + ': ' + bulkUpload.data('translate-invalid-file');
                    pError.classList.add('error');
                    div.appendChild(pError);
                    div.classList.add('messages');
                    listItem.appendChild(div);
                    fullProgressTotal.textContent = parseInt(fullProgressTotal.textContent) - 1;
                }
                listUploaded.appendChild(listItem);
                return listItemIsValid;
            })
            flow.on('filesSubmitted', (files, event) => {
                // Submit immediately.
                flow.upload();
            })
            flow.on('fileProgress', (file, chunk) => {
                const percentage = file.chunks.length ? (chunk.offset + 1) / file.chunks.length * 100 : 0;
                file.ctrl.update(percentage);
            })
            flow.on('fileSuccess', (file, responseJson, chunk) => {
                responseJson = fixJson(responseJson);
                const response = JSON.parse(responseJson);
                // The order of the files may be different from the order of
                // success uploads, so use the index.
                let index = 0;
                for (const item of listUploaded.getElementsByTagName('li')) {
                    if (item.id === file.uniqueIdentifier) {
                        break;
                    }
                    ++index;
                }
                let filesData = JSON.parse(inputFilesData.getAttribute('value'));
                filesData[index] = response.data.file;
                inputFilesData.setAttribute('value', JSON.stringify(filesData));
                file.ctrl.update(100);
                file.ctrl.ctx.fillStyle = 'green';
                const listItem = document.getElementById(file.uniqueIdentifier);
                listItem.setAttribute('data-is-uploaded', '1');
                fullProgressCurrent.textContent = $(listUploaded).find('li[data-is-valid=1][data-is-uploaded=1]').length;
                updateProgressMessage(fullProgressCurrent, fullProgressTotal, submitReady, fullProgressWait, bulkUploadActions);
            })
            flow.on('fileError', (file, responseJson) => {
                addError(submitReady, file, responseJson);
            });

            buttonPause.onclick = () => {
                if (flow.isUploading()) {
                    flow.pause();
                    buttonPause.textContent = mediaField.getAttribute('data-translate-resume');
                } else {
                    flow.resume();
                    buttonPause.textContent = mediaField.getAttribute('data-translate-pause');
                }
                updateProgressMessage(fullProgressCurrent, fullProgressTotal, submitReady, fullProgressWait, bulkUploadActions);
            };

            $(buttonSort).on('click', (ev) => {
                if (!flow.isUploading()) {
                    listSort(inputFilesData, listUploaded, ev.target.getAttribute('data-sort-type'));
                }
            });
        });

        function validateFile(file) {
            const extension = file.name.slice((file.name.lastIndexOf('.') - 1 >>> 0) + 2);
            return (!allowedMediaTypes.length || allowedMediaTypes.includes(file.type))
                && (!allowedExtensions.length || allowedExtensions.includes(extension.toLowerCase()))
                && file.name.substr(0, 1) !== '.'
                && /^[^{}$?!<>\/\\]+$/.test(file.name)
                && (allowEmptyFiles || file.size > 0);
        }

        function listSort (inputFilesData, listUploaded, sortType) {
            var sortFunction;
            if (sortType === 'ascii') {
                sortFunction = function (x, y) {
                    return x.name === y.name ? 0 : (x.name > y.name ? 1 : -1);
                }
            } else if (sortType === 'alpha') {
                sortFunction = function (x, y) {
                    return x.name.localeCompare(y.name);
                }
            } else if (sortType === 'ascii-path') {
                sortFunction = function (x, y) {
                    return x.path === y.path ? 0 : (x.path > y.path ? 1 : -1);
                }
            } else if (sortType === 'alpha-path') {
                sortFunction = function (x, y) {
                    return x.path.localeCompare(y.path);
                }
            } else {
                return;
            }

            var filesData = JSON.parse(inputFilesData.getAttribute('value'));
            filesData.sort(sortFunction);
            inputFilesData.setAttribute('value', JSON.stringify(filesData));

            var listNames = [];
            $(listUploaded).find('li').each(function () {
                listNames.push({li: this, name: $(this).data('filename'), path: $(this).data('filepath')});
            });
            listNames.sort(sortFunction);
            var html = '';
            listNames.forEach(function (item) { html += item.li.outerHTML; });
            $(listUploaded).html(html);
        }

        function humanFileSize(number) {
            if (number < 1000) {
                return number + ' bytes';
            } else if (number > 1000 && number < 1000000) {
                return (number / 1000).toFixed(0) + ' KB';
            } else if (number > 1000000) {
                return (number / 1000000).toFixed(0) + ' MB';
            }
        }

        function defaultThumbnailUrl(file) {
            const mainType = file.type.split('/')[0];
            return basePath
                + '/application/asset/thumbnails/'
                + (['audio', 'image', 'video'].includes(mainType) ? mainType : 'default')
                + '.png';
        }

        function addError(submitReady, file, responseJson) {
            responseJson = fixJson(responseJson);
            const response = JSON.parse(responseJson);
            submitReady.setAttribute('required', 'required');
            const div = document.createElement('div');
            div.classList.add('media-info');
            div.classList.add('messages');
            const pError = document.createElement('p');
            pError.textContent = response.message;
            pError.classList.add('error');
            div.appendChild(pError);
            const listItem = document.getElementById(file.uniqueIdentifier);
            listItem.appendChild(div);
        }

        function createThrobber(img) {
            const throbberWidth = 64;
            const throbberHeight = 6;
            const throbber = document.createElement('canvas');
            throbber.classList.add('upload-progress');
            throbber.setAttribute('width', throbberWidth);
            throbber.setAttribute('height', throbberHeight);
            img.parentNode.appendChild(throbber);
            throbber.ctx = throbber.getContext('2d');
            throbber.ctx.fillStyle = 'orange';
            throbber.update = function(percent) {
                throbber.ctx.fillRect(0, 0, throbberWidth * percent / 100, throbberHeight);
                if (percent === 100) {
                    throbber.ctx.fillStyle = 'green';
                }
            }
            throbber.update(0);
            return throbber;
        }

        function updateProgressMessage(fullProgressCurrent, fullProgressTotal, submitReady, fullProgressWait, bulkUploadActions) {
            if (!fullProgressTotal.textContent.length
                || parseInt(fullProgressTotal.textContent) === 0
                || (parseInt(fullProgressCurrent.textContent) >= parseInt(fullProgressTotal.textContent))
            ) {
                submitReady.removeAttribute('required');
                fullProgressWait.style.display = 'none';
                bulkUploadActions.style.display = 'block';
            } else {
                submitReady.setAttribute('required', 'required');
                fullProgressWait.style.display = 'block';
                bulkUploadActions.style.display = 'none';
            }
        }

        /**
         * A php session warning can be added to the response, breaking process,
         * so remove it.
         */
        function fixJson(json) {
            let errorPos = json.indexOf('}<br');
            return errorPos > -1
                ? json.substring(0, errorPos + 1)
                : json;
        }

    });
})(jQuery);
